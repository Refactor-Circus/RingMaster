<?php

namespace App\Support;

use App\Enums\AttentionStatus;
use App\Enums\ChoreStatus;
use App\Enums\ImprovementStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Briefing;
use App\Models\Chore;
use App\Models\Improvement;
use App\Models\Run;
use App\Models\Signal;
use App\Models\Spec;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The morning briefing: one page for what happened since the last one and
 * what needs you now. Shipped jobs, PRs waiting for review ranked from the
 * quickest read to the most careful one (each with its brief and risk),
 * questions with any precedent, proposals and chores, what the runs cost,
 * and what the queue holds next. Made at night_shift.briefing_at, or when
 * you ask.
 */
final class Briefings
{
    public function __construct(private readonly Risk $risk, private readonly Precedents $precedents, private readonly Notifier $notifier) {}

    /**
     * Whether today's briefing is due: past briefing_at and none made since.
     */
    public static function due(?CarbonImmutable $at = null): bool
    {
        $time = config('ring-master.night_shift.briefing_at', '07:30');

        if (! is_string($time) || ! preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return false;
        }

        $at ??= CarbonImmutable::now();
        $todays = $at->setTimeFromTimeString($time);

        return $at->gte($todays) && ! Briefing::query()->where('created_at', '>=', $todays)->exists();
    }

    public function generate(bool $notify = true): Briefing
    {
        $since = Briefing::query()->latest('id')->value('created_at') ?? now()->subDay();
        $since = CarbonImmutable::parse($since);

        $briefing = Briefing::query()->create(['since' => $since, 'data' => [
            'shipped' => $this->shipped($since),
            'review' => $this->review(),
            'needs' => $this->needs(),
            'runs' => $this->runs($since),
            'next' => Task::query()->with('project')->where('status', TaskStatus::Queued)->whereNull('paused_at')->unblocked()->inQueueOrder()->limit(10)->get()
                ->map(fn (Task $task): array => ['id' => $task->id, 'project' => $task->project->folder, 'title' => $task->title, 'priority' => $task->priority->label()])->all(),
            'signals' => Signal::query()->with('project')->where('status', 'open')->where('created_at', '>=', $since)->latest()->limit(20)->get()
                ->map(fn (Signal $signal): array => ['project' => $signal->project->folder, 'kind' => $signal->kind->label(), 'title' => $signal->title, 'url' => $signal->url])->all(),
        ]]);

        Activity::record('briefing.made', 'Morning briefing #'.$briefing->id);

        if ($notify) {
            $data = $briefing->data;
            $low = array_values(array_map(fn (array $row): int => $row['id'], array_filter($data['review'], fn (array $row): bool => $row['risk']['level'] === 'low')));
            $this->notifier->send('Your briefing', [
                count($data['shipped']).' shipped, '.count($data['review']).' PR(s) to review, '.$data['needs']['count'].' thing(s) need you',
                '$'.number_format((float) $data['runs']['cost'], 2).' spent on '.$data['runs']['count'].' run(s)',
            ], "/briefing/{$briefing->id}", $low === [] ? [] : [
                ActionLinks::make('review', $low[0], 'Review '.count($low).' low-risk PR'.(count($low) === 1 ? '' : 's'), ['specs' => $low]),
            ]);
        }

        return $briefing;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shipped(CarbonImmutable $since): array
    {
        $ids = Activity::query()->where('type', 'spec.status')->where('created_at', '>=', $since)
            ->where(fn ($query) => $query->where('message', 'like', '%→ in-review')->orWhere('message', 'like', '%→ merged'))
            ->pluck('spec_id')
            ->merge(Spec::query()->where('pr_merged_at', '>=', $since)->pluck('id'))
            ->filter()->unique()->all();

        return array_values(Spec::query()->with('project')->whereKey($ids)->get()->map(fn (Spec $spec): array => [
            'id' => $spec->id,
            'project' => $spec->project->folder,
            'title' => $spec->title,
            'status' => $spec->pr_merged_at !== null ? 'merged' : $spec->status,
            'prs' => $spec->pullRequests(),
        ])->all());
    }

    /**
     * PRs waiting for your review, lowest risk first.
     *
     * @return list<array<string, mixed>>
     */
    private function review(): array
    {
        return array_values(Spec::query()->with('project')->where('status', 'in-review')->whereNull('pr_merged_at')->get()
            ->map(function (Spec $spec): array {
                $brief = $this->risk->brief($spec);

                return [
                    'id' => $spec->id,
                    'project' => $spec->project->folder,
                    'title' => $spec->title,
                    'prs' => $spec->pullRequests(),
                    'risk' => $this->risk->assess($spec),
                    'brief' => $brief === null ? null : Str::limit($brief, 4000),
                ];
            })
            ->sortBy(fn (array $row): int => $row['risk']['score'])
            ->all());
    }

    /**
     * @return array{count: int, questions: list<array<string, mixed>>, proposals: int, chores: int, improvements: int}
     */
    private function needs(): array
    {
        $items = AttentionItem::query()->with('spec.project')->where('status', AttentionStatus::Open)
            ->whereHas('spec.project.workspace', fn ($query) => $query->where('enabled', true))->latest()->get();

        $questions = array_values($items->map(function (AttentionItem $item): array {
            $precedent = $this->precedents->suggestion($item);

            return [
                'id' => $item->id,
                'spec_id' => $item->spec_id,
                'project' => $item->spec->project->folder,
                'question' => $item->question,
                'kind' => $item->kind->value,
                'precedent' => $precedent === null ? null : [
                    'answer' => $precedent['option'] ?? $precedent['decision']->answer,
                    'project' => $precedent['decision']->project->folder,
                    'decided_at' => $precedent['decision']->decided_at->toDateString(),
                    'similarity' => $precedent['similarity'],
                ],
            ];
        })->all());

        $proposals = Task::query()->where('status', TaskStatus::Proposed)->count();
        $chores = Chore::query()->where('status', ChoreStatus::Open)->count();
        $improvements = Improvement::query()->whereIn('status', [ImprovementStatus::Open, ImprovementStatus::Evaluated])->count();

        return ['count' => count($questions) + $proposals + $chores + $improvements, 'questions' => $questions, 'proposals' => $proposals, 'chores' => $chores, 'improvements' => $improvements];
    }

    /**
     * @return array{count: int, succeeded: int, cost: float, failures: array<string, int>, night: ?array{spent: float, budget: ?float}}
     */
    private function runs(CarbonImmutable $since): array
    {
        $runs = Run::query()->where('started_at', '>=', $since)->get();
        // Last night's window: the one that began yesterday at night_shift.start.
        $night = NightShift::startedAt(CarbonImmutable::now()->setTimeFromTimeString((string) config('ring-master.night_shift.start', '22:00'))->subDay()->addMinute());

        return [
            'count' => $runs->count(),
            'succeeded' => $runs->where('status', RunStatus::Succeeded)->count(),
            'cost' => round((float) $runs->sum(fn (Run $run): float => (float) $run->cost_usd), 2),
            'failures' => $runs->where('status', RunStatus::Failed)->groupBy(fn (Run $run): string => $run->failure?->label() ?? 'unknown')->map->count()->all(),
            'night' => $night === null ? null : [
                'spent' => round((float) Run::query()->where('started_at', '>=', $night)->where('started_at', '<', $night->addDay())->sum('cost_usd'), 2),
                'budget' => NightShift::budget(),
            ],
        ];
    }
}

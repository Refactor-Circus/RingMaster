<?php

namespace App\Actions;

use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\JobActivity;
use App\Support\Notifier;
use App\Support\ProjectDiscovery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RefactorCircus\Ringside\Specs\Board;

/**
 * Mirrors every enabled workspace's projects and spec folders into the
 * database. The files stay the source of truth: a spec folder that is gone
 * from disk is gone from the board.
 */
final class ScanWorkspaces
{
    private const string LAST_SCAN = 'ring-master.last-scan';

    /** @var list<AttentionItem> raised by the scan in progress */
    private array $raised = [];

    public function __construct(private readonly ProjectDiscovery $discovery, private readonly Notifier $notifier, private readonly JobActivity $activity) {}

    public function handle(): void
    {
        $this->seedFromConfig();

        foreach (Workspace::query()->where('enabled', true)->get() as $workspace) {
            $this->scan($workspace);
        }

        app(ClaimTasks::class)->expire();

        Cache::forever(self::LAST_SCAN, now()->getTimestamp());
    }

    /**
     * Scans only when the last scan is older than the configured window, so
     * every open board polling at once costs one scan.
     */
    public function ifStale(): void
    {
        $last = Cache::get(self::LAST_SCAN);
        $window = (int) config('ring-master.scan.stale_after', 3);

        if (! is_int($last) || now()->getTimestamp() - $last >= $window) {
            $this->handle();
        }
    }

    public function scan(Workspace $workspace): void
    {
        if (! is_dir($workspace->path)) {
            $workspace->update(['error' => 'Folder not found.', 'scanned_at' => now()]);

            return;
        }

        DB::transaction(function () use ($workspace): void {
            $kept = [];

            foreach ($this->discovery->discover($workspace->path) as $found) {
                $project = Project::query()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'path' => $found['path']],
                    ['folder' => $found['folder'], 'is_workspace' => $found['is_workspace'], 'name' => $found['folder']],
                );

                $this->importSpecs($project);
                $kept[] = $project->id;
            }

            $workspace->projects()->whereNotIn('id', $kept)->delete();
            $workspace->update(['error' => null, 'scanned_at' => now()]);
        });

        $this->announce();
    }

    private function importSpecs(Project $project): void
    {
        $board = (new Board([$project->ringside()]))->data()['projects'][0];
        $project->update(['name' => $board['name']]);
        $keys = [];

        foreach ($board['specs'] as $spec) {
            $model = Spec::query()->firstOrNew(['project_id' => $project->id, 'key' => $spec['id']]);
            $previous = $model->exists ? $model->status : null;

            $model->fill([
                'dir' => $spec['dir'],
                'title' => $spec['title'],
                'status' => $spec['status'],
                'column' => $spec['column'],
                'ticket' => $spec['ticket'],
                'pr' => $spec['pr'],
                'branch' => $spec['branch'],
                'tasks_done' => $spec['tasks']['done'],
                'tasks_total' => $spec['tasks']['total'],
                'packages' => $spec['packages'],
                'rounds' => $spec['rounds'],
                'decisions' => $spec['decisions'],
                'decision' => $spec['decision'],
                'questions' => $spec['questions'],
                'files' => $spec['files'],
                'spec_updated_at' => $spec['updated'] > 0 ? Carbon::createFromTimestamp($spec['updated']) : null,
            ]);

            $updated = $spec['updated'] > 0 ? $spec['updated'] : null;
            $worktree = in_array($model->status, ['building', 'in-progress'], true) ? $this->activity->latest($project->ringside(), $spec['id']) : null;
            $latest = max($updated ?? 0, $worktree ?? 0);
            $model->activity_at = $latest > 0 ? CarbonImmutable::createFromTimestamp($latest) : null;
            $model->save();

            if ($previous !== null && $previous !== $model->status) {
                Activity::record('spec.status', "Spec status {$previous} → {$model->status}", spec: $model);
            }

            $this->importAttention($model, $spec);
            $keys[] = $spec['id'];
        }

        $project->specs()->whereNotIn('key', $keys)->delete();
        $this->followSpecs($project);
    }

    /**
     * Mirrors what the spec waits on you for. An item gone from the folder
     * was dealt with: applied, if you answered it here; closed otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function importAttention(Spec $spec, array $data): void
    {
        $present = [];

        if ($spec->status === 'needs-decision') {
            /** @var list<array{id: string, question: string, options: list<string>, recommendation: ?string, evidence: ?string, source: string}> $questions */
            $questions = $data['open_questions'] ?? [];

            foreach ($questions as $question) {
                $present[] = $this->upsertItem($spec, 'q:'.sha1(Str::lower($question['question'])), [
                    'kind' => AttentionKind::Decision,
                    'question' => $question['question'],
                    'options' => $question['options'],
                    'recommendation' => $question['recommendation'],
                    'evidence' => $question['evidence'],
                    'source' => $question['source'],
                ]);
            }
        }

        if ($spec->status === 'escalated') {
            $present[] = $this->upsertItem($spec, 'escalation:'.count($spec->rounds), [
                'kind' => AttentionKind::Escalation,
                'question' => 'The factory escalated this job. Accept it as it stands, or send it back with a note?',
                'options' => [AttentionKind::APPROVE, AttentionKind::REWORK],
                'recommendation' => null,
                'evidence' => Str::limit(trim((string) $spec->project->ringside()->read($spec->dir.'/decision.md')), 2000),
                'source' => 'build',
            ]);
        }

        $gone = $spec->attentionItems()->whereNotIn('id', $present)->whereIn('status', [AttentionStatus::Open, AttentionStatus::Answered])->get();

        foreach ($gone as $item) {
            $answered = $item->status === AttentionStatus::Answered;
            $item->update($answered
                ? ['status' => AttentionStatus::Applied, 'applied_at' => now()]
                : ['status' => AttentionStatus::Closed, 'closed_at' => now()]);

            Activity::record($answered ? 'attention.applied' : 'attention.closed', ($answered ? 'Applied: ' : 'Answered in a session: ').Str::limit($item->question, 120), spec: $spec);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertItem(Spec $spec, string $key, array $attributes): int
    {
        $item = AttentionItem::query()->firstOrNew(['spec_id' => $spec->id, 'key' => $key]);
        $new = ! $item->exists;
        $item->fill($attributes);

        if ($new) {
            $item->status = AttentionStatus::Open;
        }

        $item->save();

        if ($new) {
            $this->raised[] = $item;
            Activity::record('attention.raised', ($item->kind === AttentionKind::Escalation ? 'Escalated: ' : 'Question: ').Str::limit($item->question, 120), spec: $spec);
        }

        return $item->id;
    }

    /**
     * One notification for everything this scan found waiting on you.
     */
    private function announce(): void
    {
        $raised = $this->raised;
        $this->raised = [];

        if ($raised === []) {
            return;
        }

        $count = count($raised);
        $this->notifier->send(
            $count === 1 ? '1 thing needs you' : "{$count} things need you",
            array_map(fn (AttentionItem $item): string => $item->spec->project->folder.': '.Str::limit($item->question, 120), array_slice($raised, 0, 10)),
        );
    }

    /**
     * Links tasks to the spec they name once it exists, and keeps each
     * linked task's status in step with its spec's.
     */
    private function followSpecs(Project $project): void
    {
        $tasks = $project->tasks()->whereIn('status', TaskStatus::open())->get();

        foreach ($tasks as $task) {
            if ($task->spec_id === null && $task->spec_key !== null) {
                $spec = $project->specs()->where('key', $task->spec_key)->first();

                if ($spec !== null) {
                    $task->update(['spec_id' => $spec->id]);
                    Activity::record('task.linked', "Linked to spec {$spec->key}", $task);
                }
            }

            // A queued task waits for a session even when its spec exists already.
            $spec = $task->spec_id === null || $task->status === TaskStatus::Queued ? null : Spec::query()->find($task->spec_id);
            $status = $spec === null ? null : TaskStatus::fromSpec($spec->status);

            if ($status !== null && $status !== $task->status) {
                Activity::record('task.status', "Task {$task->status->label()} → {$status->label()}", $task);
                $task->update(['status' => $status]);
            }
        }
    }

    private function seedFromConfig(): void
    {
        /** @var list<string> $configured */
        $configured = config('ring-master.workspaces', []);
        $paths = [];

        foreach ($configured as $path) {
            $path = ProjectDiscovery::expand($path);
            $paths[] = $path = realpath($path) ?: $path;

            Workspace::query()->firstOrCreate(['path' => $path], ['name' => basename($path), 'from_config' => true]);
        }

        Workspace::query()->where('from_config', true)->whereNotIn('path', $paths)->delete();
    }
}

<?php

namespace App\Support;

use App\Actions\ClaimTasks;
use App\Enums\AttentionStatus;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Run;
use App\Models\Task;
use App\Runner\Runner;
use Illuminate\Support\Str;

/**
 * Why fewer sessions run than the runner allows: what holds the runner as a
 * whole, and for every open job not running, the reason it waits. Shown on
 * the Runs page, so a free slot never looks like a stall.
 */
final class Capacity
{
    public const string FOLDER = 'Folder busy: one session per folder tree';

    public const string WAITS = 'Waiting on other tasks to be done or merged';

    public const string YOU = 'Needs you';

    public const string APPLYING = 'Your answer is waiting to be applied';

    public const string PAUSED = 'Paused';

    public const string READY = 'Ready: starts within a few seconds';

    public const string HELD = 'Held by a session that ended: resumes once its claim runs out';

    public function __construct(private readonly ClaimTasks $claims) {}

    /**
     * @return array{live: int, limit: int, hold: ?string, waiting: array<string, list<string>>}
     */
    public function explain(): array
    {
        $active = Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get();
        $busy = array_values($active->map(fn (Run $run): string => $run->project->path)->all());
        $running = $active->pluck('task_id')->filter()->all();
        $waiting = [];

        $tasks = Task::query()->with(['project', 'spec', 'dependencies'])
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Claimed, TaskStatus::Running, TaskStatus::NeedsAttention])
            ->whereNotIn('id', $running)
            ->orderBy('id')
            ->get();

        foreach ($tasks as $task) {
            $reason = $this->reason($task, $busy);
            $waiting[$reason][] = "#{$task->id} {$task->project->folder}: ".Str::limit($task->title, 70);
        }

        foreach (AttentionItem::query()->with('spec.project')->where('status', AttentionStatus::Answered)->get()->groupBy(fn (AttentionItem $item): int => $item->spec->project_id) as $answers) {
            $project = $answers->first()->spec->project;

            if (Runner::overlaps($project->path, $busy)) {
                $waiting[self::FOLDER][] = "Answers for {$project->folder}";
            }
        }

        return ['live' => $active->count(), 'limit' => Runner::concurrency(), 'hold' => Runner::holdReason(), 'waiting' => $waiting];
    }

    /**
     * @param  list<string>  $busy
     */
    private function reason(Task $task, array $busy): string
    {
        $answered = $task->spec !== null && $task->spec->attentionItems()->where('status', AttentionStatus::Answered)->exists();

        return match (true) {
            $task->paused_at !== null => self::PAUSED,
            $task->dependencies->isNotEmpty() => self::WAITS,
            $answered => self::APPLYING,
            $task->status === TaskStatus::NeedsAttention || in_array($task->spec?->status, ['needs-decision', 'escalated'], true) => self::YOU,
            Runner::overlaps($task->project->path, $busy) => self::FOLDER,
            $this->claims->claimable($task->id) => self::READY,
            default => self::HELD,
        };
    }
}

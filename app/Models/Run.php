<?php

namespace App\Models;

use App\Enums\RunKind;
use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One headless Claude session the runner started.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $task_id
 * @property RunKind $kind
 * @property string $session
 * @property RunStatus $status
 * @property int|null $pid
 * @property string $log_path
 * @property int|null $exit_code
 * @property string|null $cost_usd
 * @property string|null $summary
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Task|null $task
 */
#[Fillable(['project_id', 'task_id', 'kind', 'session', 'status', 'pid', 'log_path', 'exit_code', 'cost_usd', 'summary', 'started_at', 'finished_at'])]
class Run extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => RunKind::class,
            'status' => RunStatus::class,
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\ImprovementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A change to the factory itself (a Ringside skill, a role's model, the
 * runner's allowlist) the factory analyst proposed from the evidence of past
 * jobs. Accepting it queues a task that builds it on a branch; evaluating
 * replays recent jobs against that branch and records how they did.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $title
 * @property string $target ringside, allowlist or config
 * @property string $rationale
 * @property string $evidence
 * @property string $change
 * @property ImprovementStatus $status
 * @property int|null $task_id the task that builds it
 * @property string|null $branch the Ringside branch to evaluate
 * @property array<string, mixed>|null $results replays: spec => baseline and replay numbers
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Task|null $task
 * @property-read Collection<int, Run> $runs
 */
#[Fillable(['workspace_id', 'title', 'target', 'rationale', 'evidence', 'change', 'status', 'task_id', 'branch', 'results', 'resolved_at'])]
class Improvement extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ImprovementStatus::class,
            'results' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}

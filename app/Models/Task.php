<?php

namespace App\Models;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of work you queued: an idea, a ticket or a spec, for one project.
 * Once a spec exists for it, the scanner keeps its status in step with the
 * spec's. A paused task keeps its status but no session picks it up; one
 * that depends on other tasks waits until every one of them is done (or
 * its PR is merged).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $spec_id
 * @property string|null $spec_key
 * @property TaskKind $kind
 * @property string $title
 * @property string|null $body
 * @property string|null $ticket_ref
 * @property TaskPriority $priority
 * @property TaskStatus $status
 * @property string|null $claimed_by
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $paused_at
 * @property string|null $pause_reason
 * @property string|null $proposal_reason
 * @property string|null $resume_note
 * @property int|null $campaign_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Spec|null $spec
 * @property-read Campaign|null $campaign
 * @property-read Collection<int, Task> $dependencies
 * @property-read Collection<int, Task> $dependents
 */
#[Fillable(['project_id', 'spec_id', 'spec_key', 'kind', 'title', 'body', 'ticket_ref', 'priority', 'status', 'claimed_by', 'claimed_at', 'lease_expires_at', 'attempts', 'paused_at', 'pause_reason', 'proposal_reason', 'resume_note', 'campaign_id'])]
class Task extends Model
{
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /** Whether it still waits on a task that is not done. */
    public function isBlocked(): bool
    {
        return $this->relationLoaded('dependencies') ? $this->dependencies->isNotEmpty() : $this->dependencies()->exists();
    }

    /** The status as shown: a paused or blocked task reads as such, whatever its spec says. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isBlocked() => 'Blocked',
            $this->isPaused() => 'Paused',
            default => $this->status->label(),
        };
    }

    public function statusTone(): string
    {
        return $this->isPaused() || $this->isBlocked() ? 'wait' : $this->status->tone();
    }

    /**
     * The tasks this one waits on. A row goes once that task is done.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id')->withTimestamps();
    }

    /**
     * Tasks waiting on this one.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id')->withTimestamps();
    }

    /**
     * Tasks that wait on nothing.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeUnblocked(Builder $query): void
    {
        $query->whereDoesntHave('dependencies');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Spec, $this>
     */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(Spec::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * The task's own entries and its spec's, newest first.
     *
     * @return Builder<Activity>
     */
    public function timeline(): Builder
    {
        return Activity::query()
            ->where(fn (Builder $query) => $query
                ->where('task_id', $this->id)
                ->when($this->spec_id !== null, fn (Builder $query) => $query->orWhere('spec_id', $this->spec_id)))
            ->latest()
            ->latest('id');
    }

    /**
     * Open tasks first by priority, then oldest first: the order work is picked up in.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeInQueueOrder(Builder $query): void
    {
        $query->orderByDesc('priority')->oldest()->oldest('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }
}

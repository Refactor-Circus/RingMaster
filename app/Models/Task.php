<?php

namespace App\Models;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of work you queued: an idea, a ticket or a spec, for one project.
 * Once a spec exists for it, the scanner keeps its status in step with the
 * spec's.
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read Spec|null $spec
 */
#[Fillable(['project_id', 'spec_id', 'spec_key', 'kind', 'title', 'body', 'ticket_ref', 'priority', 'status', 'claimed_by', 'claimed_at', 'lease_expires_at', 'attempts'])]
class Task extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
        ];
    }
}

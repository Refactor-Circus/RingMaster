<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One change made across many packages of a workspace: a task per package,
 * each waiting on the tasks of the packages it requires, so the change lands
 * in dependency order.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $title
 * @property string $intent
 * @property CampaignStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Collection<int, Task> $tasks
 */
#[Fillable(['workspace_id', 'title', 'intent', 'status'])]
class Campaign extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
        ];
    }
}

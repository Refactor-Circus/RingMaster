<?php

namespace App\Models;

use App\Enums\SignalKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something happening outside the spec folders that may call for a job: an
 * open GitHub issue, a check that keeps failing on the base branch now and
 * then, a Dependabot PR nobody has dealt with. The planner reads them.
 *
 * @property int $id
 * @property int $project_id
 * @property SignalKind $kind
 * @property string $key
 * @property string $title
 * @property string|null $url
 * @property string|null $detail
 * @property string $status open or resolved
 * @property CarbonImmutable $seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'kind', 'key', 'title', 'url', 'detail', 'status', 'seen_at'])]
class Signal extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => SignalKind::class,
            'seen_at' => 'datetime',
        ];
    }
}

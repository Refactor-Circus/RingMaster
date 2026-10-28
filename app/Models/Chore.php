<?php

namespace App\Models;

use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the janitor or the Dependabot check found that needs your OK,
 * such as deleting merged branches on GitHub or merging a green Dependabot
 * PR. The key identifies the finding, so the same one is raised once.
 *
 * @property int $id
 * @property int $project_id
 * @property ChoreKind $kind
 * @property string $key
 * @property string $title
 * @property string|null $detail
 * @property array<string, mixed> $payload
 * @property ChoreStatus $status
 * @property string|null $result
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'kind', 'key', 'title', 'detail', 'payload', 'status', 'result', 'resolved_at'])]
class Chore extends Model
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
            'kind' => ChoreKind::class,
            'status' => ChoreStatus::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One morning briefing: what shipped since the last one, the PRs waiting for
 * your review ranked by risk, what needs you, what the night cost and what
 * is queued next. A snapshot, so it reads the same later.
 *
 * @property int $id
 * @property CarbonImmutable $since
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['since', 'data'])]
class Briefing extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'data' => 'array',
        ];
    }
}

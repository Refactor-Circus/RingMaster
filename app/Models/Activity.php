<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a task's or spec's timeline.
 *
 * @property int $id
 * @property int|null $task_id
 * @property int|null $spec_id
 * @property string $type
 * @property string $message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['task_id', 'spec_id', 'type', 'message'])]
class Activity extends Model
{
    public static function record(string $type, string $message, ?Task $task = null, ?Spec $spec = null): self
    {
        return self::query()->create([
            'task_id' => $task?->id,
            'spec_id' => $spec !== null ? $spec->id : $task?->spec_id,
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}

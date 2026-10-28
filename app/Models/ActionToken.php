<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A single-use action behind a button in a notification: accept a proposed
 * job, approve a chore, answer a question with one of its options. It works
 * once, before it expires, and only by POST.
 *
 * @property int $id
 * @property string $token
 * @property string $action
 * @property int $subject_id
 * @property array<string, mixed>|null $payload
 * @property string $label
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['token', 'action', 'subject_id', 'payload', 'label', 'expires_at', 'used_at'])]
class ActionToken extends Model
{
    public function usable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}

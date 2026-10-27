<?php

namespace App\Support;

use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Task;

/**
 * The numbers on the navigation: what needs you (open questions and proposed
 * jobs) and what waits in the queue.
 */
final class NavCounts
{
    /**
     * @return array{inbox: int, queued: int}
     */
    public static function get(): array
    {
        return [
            'inbox' => AttentionItem::query()
                ->where('status', AttentionStatus::Open)
                ->whereHas('spec.project.workspace', fn ($query) => $query->where('enabled', true))
                ->count() + Task::query()->where('status', TaskStatus::Proposed)->count(),
            'queued' => Task::query()->where('status', TaskStatus::Queued)->count(),
        ];
    }
}

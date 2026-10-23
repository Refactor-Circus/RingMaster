<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case NeedsAttention = 'needs_attention';
    case InReview = 'in_review';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * The task status a linked spec's status implies; null keeps the task's own.
     */
    public static function fromSpec(string $status): ?self
    {
        return match ($status) {
            'ticketed', 'shaping', 'written', 'approved', 'building', 'in-progress' => self::Running,
            'needs-decision', 'escalated' => self::NeedsAttention,
            'in-review' => self::InReview,
            'merged', 'closed', 'done', 'imported' => self::Done,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NeedsAttention => 'Needs you',
            self::InReview => 'In review',
            default => ucfirst($this->value),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'zinc',
            self::Claimed, self::Running => 'sky',
            self::NeedsAttention => 'amber',
            self::InReview => 'violet',
            self::Done => 'green',
            self::Failed => 'red',
            self::Cancelled => 'zinc',
        };
    }

    /** Still waiting for or under work. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isOpen()));
    }
}

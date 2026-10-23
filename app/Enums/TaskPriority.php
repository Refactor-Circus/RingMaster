<?php

namespace App\Enums;

enum TaskPriority: int
{
    case Low = 0;
    case Normal = 1;
    case High = 2;
    case Urgent = 3;

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Normal => 'sky',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }
}

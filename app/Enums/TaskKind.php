<?php

namespace App\Enums;

enum TaskKind: string
{
    case Idea = 'idea';
    case Ticket = 'ticket';
    case Spec = 'spec';

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Ticket => 'Existing ticket',
            self::Spec => 'Existing spec',
        };
    }
}

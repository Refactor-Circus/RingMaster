<?php

namespace App\Enums;

enum RunStatus: string
{
    case Running = 'running';

    /** You asked to stop it; the runner terminates it on its next tick. */
    case Stopping = 'stopping';

    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function color(): string
    {
        return match ($this) {
            self::Running, self::Stopping => 'sky',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Stopped => 'zinc',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Running || $this === self::Stopping;
    }
}

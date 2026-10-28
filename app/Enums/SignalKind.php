<?php

namespace App\Enums;

enum SignalKind: string
{
    /** An open GitHub issue. */
    case Issue = 'issue';

    /** A check on the base branch that fails now and then. */
    case Flaky = 'flaky';

    /** A Dependabot PR open for more than a week. */
    case StaleDependabot = 'stale-dependabot';

    public function label(): string
    {
        return match ($this) {
            self::Issue => 'issue',
            self::Flaky => 'flaky check',
            self::StaleDependabot => 'stale Dependabot PR',
        };
    }
}

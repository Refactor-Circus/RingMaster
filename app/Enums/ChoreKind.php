<?php

namespace App\Enums;

/**
 * Housekeeping that reaches outside this machine, so it waits for your OK.
 */
enum ChoreKind: string
{
    /** Branches on GitHub whose work is merged: delete them. */
    case RemoteBranches = 'remote-branches';

    /** A Dependabot PR with green checks: merge it. */
    case DependabotMerge = 'dependabot-merge';

    /** A Dependabot PR with failing checks: queue a task to fix it. */
    case DependabotFix = 'dependabot-fix';

    public function action(): string
    {
        return match ($this) {
            self::RemoteBranches => 'Delete them on GitHub',
            self::DependabotMerge => 'Merge it',
            self::DependabotFix => 'Queue a fix',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RemoteBranches => 'merged branches',
            self::DependabotMerge, self::DependabotFix => 'dependabot',
        };
    }
}

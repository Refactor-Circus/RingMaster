<?php

namespace App\Enums;

/**
 * Why a run failed, read from its log, so the runner can tell what retrying
 * would fix: a usage limit passes with time, a refused command needs the
 * allowlist or the skill changed, anything else is the job itself.
 */
enum RunFailure: string
{
    /** A plan usage, rate or budget limit; waiting fixes it. */
    case Limit = 'limit';

    /** It stopped on commands the allowlist refused. */
    case Permission = 'permission';

    /** The session never started properly, or the runner went away under it. */
    case Environment = 'environment';

    /** Anything else: the work itself went wrong. */
    case Work = 'work';

    public function label(): string
    {
        return match ($this) {
            self::Limit => 'usage limit',
            self::Permission => 'refused commands',
            self::Environment => 'environment',
            self::Work => 'work failed',
        };
    }

    /**
     * Whether the attempt should not count against the task: nothing about
     * the task made it fail.
     */
    public function free(): bool
    {
        return $this === self::Limit || $this === self::Environment;
    }
}

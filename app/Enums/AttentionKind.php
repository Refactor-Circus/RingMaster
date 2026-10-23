<?php

namespace App\Enums;

enum AttentionKind: string
{
    /** An architectural question from spec review or the build. */
    case Decision = 'decision';

    /** The factory gave up on a job: accept it as is or send it back. */
    case Escalation = 'escalation';

    public const string APPROVE = 'Approve as is';

    public const string REWORK = 'Send back with a note';
}

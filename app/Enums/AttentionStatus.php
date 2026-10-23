<?php

namespace App\Enums;

enum AttentionStatus: string
{
    /** Waiting for your answer. */
    case Open = 'open';

    /** You answered here; Claude has not applied it yet. */
    case Answered = 'answered';

    /** Gone from the spec folder after you answered here: Claude applied it. */
    case Applied = 'applied';

    /** Gone from the spec folder without an answer here (answered in a session). */
    case Closed = 'closed';
}

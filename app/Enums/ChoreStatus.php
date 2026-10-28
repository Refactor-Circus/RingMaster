<?php

namespace App\Enums;

enum ChoreStatus: string
{
    case Open = 'open';

    /** You said yes and it ran. */
    case Done = 'done';

    /** You said no, or it no longer applies. */
    case Dismissed = 'dismissed';

    /** You said yes and it did not work; result says why. */
    case Failed = 'failed';
}

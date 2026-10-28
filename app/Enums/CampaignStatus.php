<?php

namespace App\Enums;

enum CampaignStatus: string
{
    /** Its tasks are queued or under way. */
    case Running = 'running';

    /** Every task is done. */
    case Done = 'done';

    /** You cancelled it; its open tasks were cancelled too. */
    case Cancelled = 'cancelled';
}

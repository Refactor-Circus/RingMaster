<?php

namespace App\Enums;

enum ImprovementStatus: string
{
    /** Proposed by the analyst; waits for you. */
    case Open = 'open';

    /** You accepted it: a task builds it on a branch. */
    case Building = 'building';

    /** Replays of recent jobs are running against its branch. */
    case Evaluating = 'evaluating';

    /** The replays finished; results shows how they did. */
    case Evaluated = 'evaluated';

    /** You kept it: its PR is yours to merge. */
    case Adopted = 'adopted';

    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

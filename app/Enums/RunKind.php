<?php

namespace App\Enums;

enum RunKind: string
{
    /** Ships one claimed task. */
    case Task = 'task';

    /** Applies the answers given in RingMaster for one project, so its jobs resume. */
    case Answers = 'answers';
}

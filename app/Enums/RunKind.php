<?php

namespace App\Enums;

enum RunKind: string
{
    /** Ships one claimed task. */
    case Task = 'task';

    /** Applies the answers given in RingMaster for one project, so its jobs resume. */
    case Answers = 'answers';

    /** Reads a workspace's roadmaps and standards feedback and suggests jobs. */
    case Plan = 'plan';

    /** The factory analyst: reads past jobs and proposes changes to the factory. */
    case Improve = 'improve';

    /** Replays a finished job against an improvement's Ringside branch, in a throwaway checkout. */
    case Replay = 'replay';
}

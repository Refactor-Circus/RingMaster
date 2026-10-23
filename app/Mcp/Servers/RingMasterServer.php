<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ClaimTask;
use App\Mcp\Tools\Heartbeat;
use App\Mcp\Tools\LinkSpec;
use App\Mcp\Tools\NextTask;
use App\Mcp\Tools\PendingAnswers;
use App\Mcp\Tools\QueueStatus;
use App\Mcp\Tools\ReleaseTask;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('RingMaster')]
#[Version('0.1.0')]
#[Instructions(<<<'TEXT'
RingMaster holds the user's queue of Ring Master work and their answers to the questions jobs stopped on. Spec folders stay the source of truth; RingMaster mirrors them.

Work through it with the /ring-master-inbox skill:
1. pending-answers for this folder: apply each answer to its spec folder first (decide, approve or rework), so stopped jobs resume.
2. next-task, then claim-task with a stable session name. Run /ring-master-ship with the task's ship_input in the task's project folder.
3. As soon as the spec folder exists, link-spec. Send heartbeat between stages (and with a progress message), or the claim expires.
4. If you cannot finish, release-task with the reason (failed: true when it cannot be done as asked).

Never answer an architectural question yourself, and never merge.
TEXT)]
class RingMasterServer extends Server
{
    protected array $tools = [
        QueueStatus::class,
        PendingAnswers::class,
        NextTask::class,
        ClaimTask::class,
        Heartbeat::class,
        LinkSpec::class,
        ReleaseTask::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}

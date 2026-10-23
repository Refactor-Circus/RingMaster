<?php

namespace App\Mcp\Tools;

use App\Actions\ClaimTasks;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Claim a queued task for this session. The claim is a lease: send heartbeat while you work, or the task goes back to the queue. Fails when another session holds it.')]
class ClaimTask extends Tool
{
    public function handle(Request $request, ClaimTasks $claims): Response
    {
        $input = $request->validate(['task_id' => ['required', 'integer'], 'session' => ['required', 'string', 'max:100']]);

        return Response::json(['task' => TaskPayload::from($claims->claim((int) $input['task_id'], $input['session']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'session' => $schema->string()->description('A name for this session, the same on every call (for example the folder name and a short random suffix).')->required(),
        ];
    }
}

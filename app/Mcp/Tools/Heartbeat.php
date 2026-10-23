<?php

namespace App\Mcp\Tools;

use App\Actions\ClaimTasks;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Renew your claim on a task, optionally adding a progress line to its timeline (for example "spec review done, factory running").')]
class Heartbeat extends Tool
{
    public function handle(Request $request, ClaimTasks $claims): Response
    {
        $input = $request->validate(['task_id' => ['required', 'integer'], 'session' => ['required', 'string'], 'message' => ['nullable', 'string', 'max:500']]);

        return Response::json(['task' => TaskPayload::from($claims->heartbeat((int) $input['task_id'], $input['session'], $input['message'] ?? null))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'session' => $schema->string()->required(),
            'message' => $schema->string()->description('Optional progress line for the timeline.'),
        ];
    }
}

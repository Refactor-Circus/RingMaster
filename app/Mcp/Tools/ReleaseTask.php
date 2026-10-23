<?php

namespace App\Mcp\Tools;

use App\Actions\ClaimTasks;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Give a claimed task back: to the queue for another session (failed: false), or mark it failed when it cannot be done as asked. Always say why.')]
class ReleaseTask extends Tool
{
    public function handle(Request $request, ClaimTasks $claims): Response
    {
        $input = $request->validate(['task_id' => ['required', 'integer'], 'session' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000'], 'failed' => ['boolean']]);

        return Response::json(['task' => TaskPayload::from($claims->release((int) $input['task_id'], $input['session'], $input['reason'], (bool) ($input['failed'] ?? false)))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'session' => $schema->string()->required(),
            'reason' => $schema->string()->required(),
            'failed' => $schema->boolean()->description('true when the task cannot be done as asked; false (default) to put it back in the queue.'),
        ];
    }
}

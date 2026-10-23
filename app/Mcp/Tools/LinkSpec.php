<?php

namespace App\Mcp\Tools;

use App\Actions\ClaimTasks;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Tell RingMaster which spec folder you created for a claimed task, as soon as it exists. From then on the task follows the spec\'s status.')]
class LinkSpec extends Tool
{
    public function handle(Request $request, ClaimTasks $claims): Response
    {
        $input = $request->validate(['task_id' => ['required', 'integer'], 'session' => ['required', 'string'], 'spec' => ['required', 'string', 'max:300']]);

        return Response::json(['task' => TaskPayload::from($claims->linkSpec((int) $input['task_id'], $input['session'], $input['spec']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->required(),
            'session' => $schema->string()->required(),
            'spec' => $schema->string()->description('The spec id or its folder, e.g. 2026-10-24-0900-exports or docs/agent/specs/2026-10-24-0900-exports.')->required(),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Actions\ClaimTasks;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The task to pick up next in this folder (highest priority, then oldest), without claiming it. Returns null when the queue is empty.')]
class NextTask extends Tool
{
    public function handle(Request $request, ClaimTasks $claims): Response
    {
        $path = $request->get('project_path');
        $task = $claims->next(is_string($path) ? $path : null);

        return Response::json(['task' => $task === null ? null : TaskPayload::from($task)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_path' => $schema->string()->description('Absolute path of the folder you are working in. A workspace root covers its packages.')->required(),
        ];
    }
}

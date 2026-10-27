<?php

namespace App\Mcp\Tools;

use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Mcp\TaskPayload;
use App\Models\AttentionItem;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('How much RingMaster work waits in this folder: queued tasks (next five in pickup order), questions that need the user, and answers waiting for you to apply.')]
class QueueStatus extends Tool
{
    public function handle(Request $request): Response
    {
        $path = $request->get('project_path');
        $path = is_string($path) ? $path : null;
        $queued = Task::query()->where('status', TaskStatus::Queued)->whereNull('paused_at')->whereNull('blocked_by_task_id')->whereHas('project', fn (Builder $query) => $query->within($path));
        $items = fn (AttentionStatus $status) => AttentionItem::query()->where('status', $status)->whereHas('spec.project', fn (Builder $query) => $query->within($path))->count();

        return Response::json([
            'queued' => (clone $queued)->count(),
            'needs_user' => $items(AttentionStatus::Open),
            'answers_to_apply' => $items(AttentionStatus::Answered),
            'next' => $queued->inQueueOrder()->limit(5)->get()->map(TaskPayload::from(...))->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_path' => $schema->string()->description('Absolute path of the folder you are working in. A workspace root covers its packages. Omit for everything.'),
        ];
    }
}

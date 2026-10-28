<?php

namespace App\Mcp\Tools;

use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Answers the user gave in RingMaster that are not in the spec folders yet, grouped by spec. Apply each one with /ring-master-decide (decisions) or /ring-master-approve and /ring-master-rework (escalations); RingMaster marks it applied once it is gone from the folder.')]
class PendingAnswers extends Tool
{
    public function handle(Request $request): Response
    {
        $path = $request->get('project_path');

        $items = AttentionItem::query()
            ->with('spec.project')
            ->where('status', AttentionStatus::Answered)
            ->whereHas('spec.project', fn (Builder $query) => $query->within(is_string($path) ? $path : null))
            ->orderBy('spec_id')
            ->orderBy('id')
            ->get();

        $specs = $items->groupBy('spec_id')->map(fn ($answers) => [
            'project_path' => $answers->first()->spec->project->path,
            'spec' => $answers->first()->spec->key,
            'spec_dir' => $answers->first()->spec->dir,
            // Absolute, in the job worktree while the job is open: write the answers there.
            'spec_path' => $answers->first()->spec->folder(),
            'spec_status' => $answers->first()->spec->status,
            'task_ids' => $answers->first()->spec->tasks()->whereIn('status', TaskStatus::open())->pluck('id')->all(),
            'answers' => $answers->map(fn (AttentionItem $item) => [
                'kind' => $item->kind->value,
                'question' => $item->question,
                'answer' => $item->answer,
                'note' => $item->note,
                'source' => $item->source,
                'answered_at' => $item->answered_at?->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();

        return Response::json(['specs' => $specs]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_path' => $schema->string()->description('Absolute path of the folder you are working in. A workspace root covers its packages.')->required(),
        ];
    }
}

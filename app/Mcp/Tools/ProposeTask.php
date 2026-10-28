<?php

namespace App\Mcp\Tools;

use App\Actions\TaskProposals;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Propose a follow-up job your claimed task needs but should not do itself, such as a fix in another package. It waits in the Inbox until the user accepts it; nothing runs it before then. With blocks (default true) your task waits on it and resumes by itself once it is done; then release-task with the reason. A job an open task in that project already covers (a campaign task, say) is linked instead of proposed again. With waits_for_this the follow-up instead waits for your task (its PR merged), as when your change breaks a package that uses it; your task carries on.')]
class ProposeTask extends Tool
{
    public function handle(Request $request, TaskProposals $proposals): Response
    {
        $input = $request->validate([
            'task_id' => ['required', 'integer'],
            'session' => ['required', 'string'],
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:250'],
            'body' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
            'blocks' => ['boolean'],
            'waits_for_this' => ['boolean'],
            'not_duplicate' => ['boolean'],
        ]);

        $proposal = $proposals->propose((int) $input['task_id'], $input['session'], $input['project'], $input['title'], $input['body'], $input['reason'], (bool) ($input['blocks'] ?? ! ($input['waits_for_this'] ?? false)), (bool) ($input['waits_for_this'] ?? false), (bool) ($input['not_duplicate'] ?? false));

        if (! $proposal->wasRecentlyCreated) {
            return Response::json(['already_covered_by' => TaskPayload::from($proposal), 'note' => "Not proposed: task #{$proposal->id} in that project already covers it, and is linked to your task instead. Pass not_duplicate: true only if your change is a different one."]);
        }

        return Response::json(['proposed' => TaskPayload::from($proposal)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_id' => $schema->integer()->description('The task that needs it: your claimed task, or (applying answers) one of the spec\'s task_ids from pending-answers.')->required(),
            'session' => $schema->string()->required(),
            'project' => $schema->string()->description('Folder name of the project the job is for, in the same workspace (e.g. Atrium).')->required(),
            'title' => $schema->string()->required(),
            'body' => $schema->string()->description('What to change and why, written as the task for a fresh session.')->required(),
            'reason' => $schema->string()->description('Why your task needs it, shown to the user.')->required(),
            'blocks' => $schema->boolean()->description('true (the default unless waits_for_this): your task waits on it and resumes once it is done.'),
            'waits_for_this' => $schema->boolean()->description('true: the follow-up waits for your task to be done or merged, and your task does not wait on it. For fixes in packages your change breaks.'),
            'not_duplicate' => $schema->boolean()->description('true: create it even though an open task in that project has a title like it. Without it, such a task is linked instead and returned as already_covered_by.'),
        ];
    }
}

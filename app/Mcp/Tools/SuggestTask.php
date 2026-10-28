<?php

namespace App\Mcp\Tools;

use App\Actions\TaskProposals;
use App\Mcp\TaskPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Suggest a new job for a package, as the portfolio planner. It waits in the Inbox until the user accepts it; nothing runs it before then. A suggestion with the same title as an open task in that package is refused.')]
class SuggestTask extends Tool
{
    public function handle(Request $request, TaskProposals $proposals): Response
    {
        $input = $request->validate([
            'session' => ['required', 'string'],
            'project' => ['required', 'string'],
            'title' => ['required', 'string', 'max:250'],
            'body' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return Response::json(['suggested' => TaskPayload::from($proposals->suggest($input['session'], $input['project'], $input['title'], $input['body'], $input['reason']))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session' => $schema->string()->description('Your session name, from the prompt.')->required(),
            'project' => $schema->string()->description('Folder name of the package the job is for (e.g. Atrium).')->required(),
            'title' => $schema->string()->required(),
            'body' => $schema->string()->description('The task for a fresh session: what to change, where, and how to tell it is done.')->required(),
            'reason' => $schema->string()->description('Why now, naming what you read it in. Shown to the user.')->required(),
        ];
    }
}

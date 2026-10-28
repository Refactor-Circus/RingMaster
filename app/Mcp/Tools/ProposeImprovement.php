<?php

namespace App\Mcp\Tools;

use App\Actions\Improvements;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Propose a change to the factory itself, as the factory analyst: a Ringside skill, workflow or default, the runner allowlist, or another RingMaster setting. It waits on the Factory page; nothing changes until the user accepts it.')]
class ProposeImprovement extends Tool
{
    public function handle(Request $request, Improvements $improvements): Response
    {
        $input = $request->validate([
            'session' => ['required', 'string'],
            'title' => ['required', 'string', 'max:200'],
            'target' => ['required', 'string'],
            'rationale' => ['required', 'string', 'max:4000'],
            'evidence' => ['required', 'string', 'max:8000'],
            'change' => ['required', 'string', 'max:8000'],
        ]);

        $improvement = $improvements->propose($input['session'], $input['title'], $input['target'], $input['rationale'], $input['evidence'], $input['change']);

        return Response::json(['proposed' => ['id' => $improvement->id, 'title' => $improvement->title, 'target' => $improvement->target]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session' => $schema->string()->description('Your session name, from the prompt.')->required(),
            'title' => $schema->string()->required(),
            'target' => $schema->string()->enum(Improvements::TARGETS)->description('ringside (a skill, workflow or default), allowlist (runner.allowed_tools) or config (another RingMaster setting).')->required(),
            'rationale' => $schema->string()->description('What goes wrong today and why this change fixes it.')->required(),
            'evidence' => $schema->string()->description('The runs, specs, post-mortems or files it rests on.')->required(),
            'change' => $schema->string()->description('Exactly what to edit, written as the task for a fresh session.')->required(),
        ];
    }
}

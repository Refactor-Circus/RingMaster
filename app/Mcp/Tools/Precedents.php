<?php

namespace App\Mcp\Tools;

use App\Support\Precedents as PastDecisions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Past architectural answers the user gave to questions like this one, closest first. Call it before writing a question to questions.yml: still ask (never apply a precedent yourself), but recommend the option that matches the past answer and cite it first in the evidence.')]
class Precedents extends Tool
{
    public function handle(Request $request, PastDecisions $precedents): Response
    {
        $input = $request->validate(['question' => ['required', 'string', 'max:2000']]);

        return Response::json(['precedents' => array_map(fn (array $match): array => [
            'question' => $match['decision']->question,
            'answer' => $match['decision']->answer,
            'note' => $match['decision']->note,
            'project' => $match['decision']->project->folder,
            'decided_at' => $match['decision']->decided_at->toDateString(),
            'similarity' => $match['similarity'],
        ], $precedents->similar($input['question'], limit: 5, min: 0.3))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('The question you are about to ask the user.')->required(),
            'project_path' => $schema->string()->description('Absolute path of the folder you are working in (optional; precedents come from every project).'),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\AnswerAttentionItem;
use App\Actions\Chores;
use App\Actions\TaskProposals;
use App\Models\ActionToken;
use App\Models\AttentionItem;
use App\Models\Chore;
use App\Models\Spec;
use App\Models\Task;
use App\Support\Reviews;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single-use links behind notification buttons. GET shows what the link
 * does with a button to confirm; POST (the button, or ntfy's http action)
 * does it, once.
 */
class ActionController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $action = $this->find($request, $token);

        return view('act', ['action' => $action, 'done' => null, 'usable' => $action->usable()]);
    }

    public function perform(Request $request, string $token): View|JsonResponse
    {
        $action = $this->find($request, $token);

        // Claim it first, so two taps cannot both act.
        $claimed = DB::table('action_tokens')->where('id', $action->id)->whereNull('used_at')->where('expires_at', '>', now())->update(['used_at' => now()]);

        if ($claimed === 0) {
            return $this->respond($request, $action, 'This link was already used or has expired.', Response::HTTP_GONE);
        }

        try {
            $done = $this->run($action);
        } catch (ValidationException $e) {
            $done = implode(' ', $e->validator->errors()->all());
        }

        return $this->respond($request, $action->refresh(), $done);
    }

    private function run(ActionToken $action): string
    {
        $payload = $action->payload ?? [];

        return match ($action->action) {
            'proposal.accept' => 'Queued: '.app(TaskProposals::class)->accept(Task::query()->findOrFail($action->subject_id))->title,
            'proposal.decline' => 'Declined: '.app(TaskProposals::class)->dismiss(Task::query()->findOrFail($action->subject_id))->title,
            'chore.approve' => (string) app(Chores::class)->approve(Chore::query()->findOrFail($action->subject_id))->result,
            'chore.dismiss' => 'Left: '.app(Chores::class)->dismiss(Chore::query()->findOrFail($action->subject_id))->title,
            'answer' => 'Answered: '.app(AnswerAttentionItem::class)->handle(AttentionItem::query()->findOrFail($action->subject_id), (string) ($payload['answer'] ?? ''))->answer,
            'review' => implode("\n", Spec::query()->with('project')->whereKey(is_array($payload['specs'] ?? null) ? $payload['specs'] : [$action->subject_id])->where('status', 'in-review')->get()
                ->flatMap(fn (Spec $spec): array => app(Reviews::class)->approve($spec))->all()) ?: 'Nothing left in review to approve.',
            default => 'Unknown action.',
        };
    }

    private function find(Request $request, string $token): ActionToken
    {
        abort_unless($request->hasValidRelativeSignature(), Response::HTTP_FORBIDDEN, 'This link is not valid.');

        return ActionToken::query()->where('token', $token)->firstOrFail();
    }

    private function respond(Request $request, ActionToken $action, string $done, int $status = 200): View|JsonResponse
    {
        if ($request->expectsJson() || ! str_contains((string) $request->header('Accept'), 'text/html')) {
            return response()->json(['done' => $done], $status);
        }

        return view('act', ['action' => $action, 'done' => $done, 'usable' => false]);
    }
}

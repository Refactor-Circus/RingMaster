<?php

use App\Actions\ScanWorkspaces;
use App\Actions\TaskProposals;
use App\Enums\AttentionStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\NeedsYou;
use App\Support\ActionLinks;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()], 'ring-master.notifications.app_url' => 'http://mac.tailnet.ts.net']);
    app(ScanWorkspaces::class)->handle();
    $this->proposal = Task::query()->create(['project_id' => Project::query()->where('folder', 'Beta')->sole()->id, 'kind' => TaskKind::Idea, 'title' => 'Fix Beta', 'status' => TaskStatus::Proposed, 'priority' => 1]);
});

function relative(string $url): string
{
    return substr($url, strlen('http://mac.tailnet.ts.net'));
}

it('makes signed single-use links on the configured address', function () {
    $link = ActionLinks::make('proposal.accept', $this->proposal->id, 'Accept and queue');

    expect($link['url'])->toStartWith('http://mac.tailnet.ts.net/act/')->toContain('signature=')->toContain('expires=');

    config(['ring-master.phone.actions' => false]);
    expect(ActionLinks::make('proposal.accept', $this->proposal->id, 'x'))->toBeNull();
});

it('confirms on GET, acts once on POST, and refuses a tampered or used link', function () {
    $url = relative(ActionLinks::make('proposal.accept', $this->proposal->id, 'Accept and queue')['url']);

    $this->get($url)->assertOk()->assertSee('Accept and queue')->assertSee('Works once');
    expect($this->proposal->refresh()->status)->toBe(TaskStatus::Proposed);

    $this->postJson($url)->assertOk()->assertJson(['done' => 'Queued: Fix Beta']);
    expect($this->proposal->refresh()->status)->toBe(TaskStatus::Queued);

    $this->postJson($url)->assertStatus(410);
    $this->postJson(str_replace('signature=', 'signature=x', $url))->assertForbidden();
});

it('answers a question with the option on its button', function () {
    $item = AttentionItem::query()->where('question', 'Sync or queued?')->sole();
    $url = relative(ActionLinks::make('answer', $item->id, 'Queued', ['answer' => 'Queued'])['url']);

    $this->postJson($url)->assertOk();

    expect($item->refresh())->status->toBe(AttentionStatus::Answered)->answer->toBe('Queued');
});

it('pushes to ntfy with the buttons as http actions', function () {
    Http::fake();
    config(['ring-master.notifications.ntfy_url' => 'https://ntfy.sh/rm-secret', 'ring-master.notifications.ntfy_token' => 'tk']);

    (new NtfyChannel)->send(new stdClass, new NeedsYou('A follow-up job is proposed', ['Beta: Fix Beta'], 'http://mac/inbox', actions: [['label' => 'Accept', 'url' => 'http://mac/act/x']]));

    Http::assertSent(fn ($request) => $request->url() === 'https://ntfy.sh'
        && $request['topic'] === 'rm-secret'
        && $request['actions'][0] === ['action' => 'http', 'label' => 'Accept', 'url' => 'http://mac/act/x', 'method' => 'POST', 'headers' => ['Accept' => 'application/json'], 'clear' => true]
        && $request->hasHeader('Authorization', 'Bearer tk'));
});

it('puts accept and decline buttons on a proposed follow-up', function () {
    Http::fake();
    config(['ring-master.notifications.channels' => ['ntfy'], 'ring-master.notifications.ntfy_url' => 'https://ntfy.sh/rm']);
    $task = Task::query()->create(['project_id' => Project::query()->where('folder', 'Alpha')->sole()->id, 'kind' => TaskKind::Idea, 'title' => 'Needs Beta', 'status' => TaskStatus::Claimed, 'claimed_by' => 's1', 'priority' => 1]);

    app(TaskProposals::class)->propose($task->id, 's1', 'Beta', 'Add a hook', 'body', 'reason');

    Http::assertSent(fn ($request) => array_column($request['actions'], 'label') === ['Accept and queue', 'Decline']);
});

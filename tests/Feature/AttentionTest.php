<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ScanWorkspaces;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Livewire\AnswerItem;
use App\Livewire\NavCounter;
use App\Livewire\ShowSpec;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Spec;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    $this->dir = 'Alpha/docs/agent/specs/2026-10-23-1430-import';
    app(ScanWorkspaces::class)->handle();
});

it('imports each open question once, with its options and recommendation', function () {
    app(ScanWorkspaces::class)->handle();

    $items = AttentionItem::query()->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and($items[0])->kind->toBe(AttentionKind::Decision)->status->toBe(AttentionStatus::Open)->source->toBe('build')->evidence->toBe('src/Import.php:40')
        ->and($items[0]->orderedOptions())->toBe(['Per line', 'Per file'])
        ->and(Activity::query()->where('type', 'attention.raised')->count())->toBe(2);
});

it('marks an answered question applied, and an unanswered one closed, once they leave the spec folder', function () {
    [$first, $second] = AttentionItem::query()->orderBy('id')->get()->all();
    app(AnswerAttentionItem::class)->handle($first, 'Per line', 'Imports are line based.');

    rewriteFixture($this->root, $this->dir.'/questions.yml', null);
    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: approved\n");
    app(ScanWorkspaces::class)->ifStale();

    expect($first->fresh())->status->toBe(AttentionStatus::Applied)->applied_at->not->toBeNull()
        ->and($second->fresh())->status->toBe(AttentionStatus::Closed)
        ->and(Activity::query()->pluck('message')->all())->toContain('Spec status needs-decision → approved');
});

it('raises an escalation with approve and send-back options and the reasons as evidence', function () {
    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: escalated\n");
    rewriteFixture($this->root, $this->dir.'/decision.md', "# ESCALATED\n\nNo progress in two rounds.\n");
    app(ScanWorkspaces::class)->ifStale();

    $escalation = AttentionItem::query()->where('kind', AttentionKind::Escalation)->sole();

    expect($escalation->options)->toBe([AttentionKind::APPROVE, AttentionKind::REWORK])
        ->and($escalation->evidence)->toContain('No progress in two rounds.')
        ->and(AttentionItem::query()->where('kind', AttentionKind::Decision)->pluck('status')->unique()->all())->toBe([AttentionStatus::Closed]);
});

it('raises a new escalation when the job escalates again after an answer', function () {
    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: escalated\n");
    rewriteFixture($this->root, $this->dir.'/decision.md', "# ESCALATED\n\nNo progress in two rounds.\n");
    app(ScanWorkspaces::class)->ifStale();
    $first = AttentionItem::query()->where('kind', AttentionKind::Escalation)->sole();
    app(AnswerAttentionItem::class)->handle($first, AttentionKind::REWORK, 'Split the group.');

    rewriteFixture($this->root, $this->dir.'/decision.md', "# ESCALATED\n\nGroup 2 stopped three times.\n");
    app(ScanWorkspaces::class)->ifStale();

    $open = AttentionItem::query()->where('kind', AttentionKind::Escalation)->where('status', AttentionStatus::Open)->sole();

    expect($first->fresh()->status)->toBe(AttentionStatus::Applied)
        ->and($open->evidence)->toContain('Group 2 stopped three times.');
});

it('answers with an option, your own words, or a change before Claude applies it', function () {
    $item = AttentionItem::query()->orderBy('id')->first();

    Livewire::test(AnswerItem::class, ['item' => $item])
        ->assertSee('One row per file or per line?')
        ->assertSee('Recommended')
        ->set('choice', 'Per file')
        ->set('note', 'Files are small.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Waiting for Claude to apply')
        ->call('edit')
        ->set('choice', AnswerItem::OTHER)
        ->set('other', 'Per sheet')
        ->call('save')
        ->assertSee('Per sheet');

    expect($item->fresh())->status->toBe(AttentionStatus::Answered)->answer->toBe('Per sheet')->note->toBe('Files are small.');
});

it('needs an answer, and a note to send an escalated job back', function () {
    $item = AttentionItem::query()->orderBy('id')->first();

    Livewire::test(AnswerItem::class, ['item' => $item])->call('save')->assertHasErrors(['answer']);

    $escalation = AttentionItem::query()->create(['spec_id' => Spec::query()->first()->id, 'key' => 'escalation:1', 'kind' => AttentionKind::Escalation, 'question' => 'Accept?', 'options' => [AttentionKind::APPROVE, AttentionKind::REWORK]]);

    Livewire::test(AnswerItem::class, ['item' => $escalation->fresh()])
        ->set('choice', AttentionKind::REWORK)
        ->call('save')
        ->assertHasErrors(['note'])
        ->set('note', 'Split the importer')
        ->call('save')
        ->assertHasNoErrors();
});

it('refuses to change an answer that was already applied', function () {
    $item = AttentionItem::query()->orderBy('id')->first();
    $item->update(['status' => AttentionStatus::Applied]);

    app(AnswerAttentionItem::class)->handle($item, 'Per file');
})->throws(ValidationException::class);

it('lists what needs you, what waits for Claude, and counts it in the nav', function () {
    $item = AttentionItem::query()->orderBy('id')->first();
    app(AnswerAttentionItem::class)->handle($item, 'Per line');

    $this->get(route('inbox'))
        ->assertOk()
        ->assertSeeInOrder(['Needs you', 'Sync or queued?', 'Answered, waiting for Claude', 'One row per file or per line?'])
        ->assertSee('Inbox');

    // One question is still open, so the nav shows 1 beside Inbox.
    $this->get(route('board'))->assertSeeTextInOrder(['Board', 'Inbox', '1', 'Tasks', 'Workspaces']);
});

it('asks before approving an escalation that still has unbuilt tasks', function () {
    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: escalated\n");
    rewriteFixture($this->root, $this->dir.'/decision.md', "# ESCALATED\n\nGroup 2 stopped.\n");
    app(ScanWorkspaces::class)->ifStale();
    $escalation = AttentionItem::query()->where('kind', AttentionKind::Escalation)->sole();
    $escalation->spec->update(['tasks_done' => 6, 'tasks_total' => 28]);

    Livewire::test(AnswerItem::class, ['item' => $escalation])
        ->set('choice', AttentionKind::APPROVE)
        ->assertSee('Only 6 of 28 tasks are built')
        ->call('save')
        ->assertHasErrors('answer')
        ->set('acceptUnbuilt', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($escalation->fresh())->status->toBe(AttentionStatus::Answered)->answer->toBe(AttentionKind::APPROVE);
});

it('sends a job in review back with a note, held until the job leaves review', function () {
    rewriteFixture($this->root, $this->dir.'/questions.yml', null);
    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: in-review\n");
    app(ScanWorkspaces::class)->ifStale();
    $spec = Spec::query()->where('key', '2026-10-23-1430-import')->sole();
    $task = $spec->project->tasks()->create(['kind' => 'spec', 'title' => 'CSV import', 'spec_id' => $spec->id, 'status' => TaskStatus::InReview, 'paused_at' => now(), 'pause_reason' => 'Paused by you']);

    Livewire::test(ShowSpec::class, ['spec' => $spec])
        ->assertSee('Send back to the factory')
        ->call('sendBack')
        ->assertHasErrors('note')
        ->set('sendBackNote', 'Split group 2.')
        ->call('sendBack')
        ->assertHasNoErrors()
        ->assertDontSee('Send back to the factory');

    $item = AttentionItem::query()->where('source', 'review')->sole();
    expect($item)->status->toBe(AttentionStatus::Answered)->answer->toBe(AttentionKind::REWORK)->note->toBe('Split group 2.')
        ->and($task->fresh()->isPaused())->toBeFalse();

    app(ScanWorkspaces::class)->handle();
    expect($item->fresh()->status)->toBe(AttentionStatus::Answered);

    rewriteFixture($this->root, $this->dir.'/meta.yml', "title: CSV import\nstatus: building\n");
    app(ScanWorkspaces::class)->ifStale();
    expect($item->fresh()->status)->toBe(AttentionStatus::Applied);
});

it('pushes the navigation counts to the page as they change', function () {
    Livewire::test(NavCounter::class)
        ->call('check')
        ->assertDispatched('ring-master-counts', inbox: 2, queued: 0);

    AttentionItem::query()->first()->update(['status' => AttentionStatus::Closed]);

    Livewire::test(NavCounter::class)
        ->call('check')
        ->assertDispatched('ring-master-counts', inbox: 1, queued: 0);
});

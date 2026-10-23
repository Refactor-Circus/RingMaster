<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ScanWorkspaces;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Livewire\AnswerItem;
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

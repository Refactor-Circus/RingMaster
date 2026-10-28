<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ScanWorkspaces;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Livewire\AnswerItem;
use App\Mcp\Servers\RingMasterServer;
use App\Mcp\Tools\Precedents as PrecedentsTool;
use App\Models\AttentionItem;
use App\Models\Decision;
use App\Models\Spec;
use App\Support\Precedents;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->spec = Spec::query()->where('key', '2026-10-23-1430-import')->sole();
});

function decisionItem(Spec $spec, string $question, array $options): AttentionItem
{
    return AttentionItem::query()->create(['spec_id' => $spec->id, 'key' => 'q:'.sha1($question), 'kind' => AttentionKind::Decision, 'question' => $question, 'options' => $options, 'status' => AttentionStatus::Open]);
}

it('keeps every answer to a decision as precedent, replacing a changed one', function () {
    $item = AttentionItem::query()->where('question', 'Sync or queued?')->sole();

    app(AnswerAttentionItem::class)->handle($item, 'Queued', 'big files');
    app(AnswerAttentionItem::class)->handle($item->refresh(), 'Sync');

    expect(Decision::query()->sole())->answer->toBe('Sync')->spec_key->toBe('2026-10-23-1430-import')->terms->toBe('queu sync');
});

it('matches questions on their words, not their phrasing', function () {
    expect(Precedents::similarity(Precedents::terms('Should the exports be queued, or sync?'), Precedents::terms('Queued or sync exports?')))->toBe(1.0)
        ->and(Precedents::similarity(Precedents::terms('Queued or sync exports?'), Precedents::terms('Which logo colour?')))->toBe(0.0)
        ->and(Precedents::matchingOption('queued', ['Run in sync', 'Queued job']))->toBe('Queued job');
});

it('suggests the past answer on a similar new question and answers the same in one click', function () {
    app(AnswerAttentionItem::class)->handle(AttentionItem::query()->where('question', 'Sync or queued?')->sole(), 'Queued', 'big files');
    $new = decisionItem($this->spec, 'Exports: sync or queued?', ['Sync', 'Queued']);

    Livewire::test(AnswerItem::class, ['item' => $new])
        ->assertSee('You chose')
        ->assertSee('Answer the same')
        ->call('usePrecedent');

    expect($new->refresh())->status->toBe(AttentionStatus::Answered)->answer->toBe('Queued')->note->toContain('Same as')->toContain('big files');
});

it('never suggests an unrelated precedent', function () {
    app(AnswerAttentionItem::class)->handle(AttentionItem::query()->where('question', 'Sync or queued?')->sole(), 'Queued');

    Livewire::test(AnswerItem::class, ['item' => decisionItem($this->spec, 'Which logo colour?', ['Red', 'Blue'])])->assertDontSee('Answer the same');
});

it('gives sessions the precedents for a question they are about to ask', function () {
    app(AnswerAttentionItem::class)->handle(AttentionItem::query()->where('question', 'Sync or queued?')->sole(), 'Queued');

    RingMasterServer::tool(PrecedentsTool::class, ['question' => 'Should the import be sync or queued?'])
        ->assertOk()
        ->assertSee('"answer":"Queued"')
        ->assertSee('"project":"Alpha"');
});

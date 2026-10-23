<?php

use App\Actions\ScanWorkspaces;
use App\Livewire\ShowSpec;
use App\Models\Spec;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->spec = Spec::query()->where('key', '2026-10-23-1430-import')->sole();
});

it('opens a spec that needs you on its decision, with files and round reports listed', function () {
    $this->get(route('specs.show', $this->spec))
        ->assertOk()
        ->assertSee('2 questions waiting for you')
        ->assertSee('One row per file or per line?')
        ->assertSeeInOrder(['Round 1', 'gate', 'pass', 'code', 'changes'])
        ->assertSee('https://github.com/acme/alpha/issues/42');
});

it('renders markdown with raw HTML escaped', function () {
    Livewire::test(ShowSpec::class, ['spec' => $this->spec])
        ->set('file', $this->spec->dir.'/spec.md')
        ->assertSeeHtml('<h1>CSV import</h1>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('refuses files outside the spec folder', function () {
    Livewire::test(ShowSpec::class, ['spec' => $this->spec])
        ->set('file', 'secret.md')
        ->assertDontSee('do not show')
        ->assertSee('That file is not readable here.')
        ->set('file', $this->spec->dir.'/../../../../secret.md')
        ->assertDontSee('do not show');
});

it('goes back to the board when the spec folder is deleted', function () {
    $page = Livewire::test(ShowSpec::class, ['spec' => $this->spec]);
    File::deleteDirectory($this->root.'/Alpha/'.$this->spec->dir);
    $this->travel(10)->seconds();

    $page->call('refresh')->assertRedirect(route('board'));
});

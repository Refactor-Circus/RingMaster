<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ScanWorkspaces;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Spec;
use App\Support\Janitor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// A job's spec lives in its worktree, on the job branch, until its PR merges.

const WORKTREE_SPEC = '2026-10-28-0900-export';

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    $this->alpha = $this->root.'/Alpha';
    $this->tree = $this->root.'/Alpha--'.WORKTREE_SPEC;
    $this->dir = 'docs/agent/specs/'.WORKTREE_SPEC;
});

/**
 * Runs git in a folder, failing the test when it fails.
 */
function treeGit(string $path, string ...$args): string
{
    $result = Process::path($path)->run(['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', '-c', 'init.defaultBranch=main', ...$args]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    return trim($result->output());
}

/**
 * Alpha as a git repo whose job worktree holds a spec on ring-master/<spec>,
 * its meta.yml committed there and nowhere else.
 */
function specInWorktree(string $alpha, string $tree, string $dir, string $meta): void
{
    treeGit($alpha, 'init', '-q');
    treeGit($alpha, 'add', '-A');
    treeGit($alpha, 'commit', '-qm', 'first');
    treeGit($alpha, 'worktree', 'add', '-q', '-b', 'ring-master/'.WORKTREE_SPEC, $tree);
    mkdir("{$tree}/{$dir}", 0o755, true);
    file_put_contents("{$tree}/{$dir}/meta.yml", $meta);
    treeGit($tree, 'add', '-A');
    treeGit($tree, 'commit', '-qm', WORKTREE_SPEC.': shaped');
}

it('scans a spec from its job worktree, with where it lives', function () {
    specInWorktree($this->alpha, $this->tree, $this->dir, "title: Export\nstatus: approved\n");

    app(ScanWorkspaces::class)->handle();
    $spec = Spec::query()->where('key', WORKTREE_SPEC)->sole();

    expect($spec->status)->toBe('approved')
        ->and($spec->dir)->toBe($this->dir)
        ->and($spec->worktree)->toBe(realpath($this->tree))
        ->and($spec->folder())->toBe(realpath($this->tree).'/'.$this->dir)
        ->and($spec->read('meta.yml'))->toContain('title: Export')
        ->and(is_dir($this->alpha.'/'.$this->dir))->toBeFalse();
});

it('sets a fixed status in the worktree\'s meta.yml and commits it on the job branch', function () {
    specInWorktree($this->alpha, $this->tree, $this->dir, "title: Export\nstatus: shaped\n");
    app(ScanWorkspaces::class)->handle();

    app(AnswerAttentionItem::class)->handle(AttentionItem::query()->where('source', 'status')->sole(), 'Build it (approved)');

    expect(file_get_contents("{$this->tree}/{$this->dir}/meta.yml"))->toBe("title: Export\nstatus: approved\n")
        ->and(treeGit($this->tree, 'log', '-1', '--format=%s'))->toBe(WORKTREE_SPEC.': status set to approved in RingMaster')
        ->and(treeGit($this->tree, 'status', '--porcelain'))->toBe('')
        ->and(treeGit($this->alpha, 'log', '-1', '--format=%s', 'main'))->toBe('first');
});

it('keeps a merged job worktree until its spec reaches the checkout, then removes it', function () {
    specInWorktree($this->alpha, $this->tree, $this->dir, "title: Export\nstatus: in-review\n");
    treeGit($this->root, 'init', '--bare', '-q', 'origin.git');
    treeGit($this->alpha, 'remote', 'add', 'origin', $this->root.'/origin.git');
    treeGit($this->alpha, 'push', '-q', '-u', 'origin', 'main');
    treeGit($this->alpha, 'remote', 'set-head', 'origin', 'main');
    // The PR merges on GitHub: origin's main has the job, the checkout does not yet, and holds a dirty file that stops its update.
    treeGit($this->tree, 'push', '-q', 'origin', 'ring-master/'.WORKTREE_SPEC.':main');
    file_put_contents($this->alpha.'/composer.json', '{"name": "acme/alpha", "dirty": true}');
    app(ScanWorkspaces::class)->handle();
    $project = Project::query()->where('folder', 'Alpha')->sole();

    $held = app(Janitor::class)->project($project);

    expect($held['worktrees'])->toBe([])
        ->and($held['skipped'])->toContain('worktree '.realpath($this->tree).': its spec is not on the checkout yet')
        ->and(is_dir($this->tree))->toBeTrue();

    treeGit($this->alpha, 'checkout', '--', 'composer.json');
    $done = app(Janitor::class)->project($project);

    expect($done['updated'])->toBe(['fast-forwarded main 1 commit(s)'])
        ->and($done['worktrees'])->toBe([realpath($this->tree)])
        ->and(is_file("{$this->alpha}/{$this->dir}/meta.yml"))->toBeTrue();
});

it('drops an untracked copy the merge brings unchanged before it fast-forwards, and keeps one that differs', function () {
    specInWorktree($this->alpha, $this->tree, $this->dir, "title: Export\nstatus: in-review\n");
    treeGit($this->root, 'init', '--bare', '-q', 'origin.git');
    treeGit($this->alpha, 'remote', 'add', 'origin', $this->root.'/origin.git');
    treeGit($this->alpha, 'push', '-q', '-u', 'origin', 'main');
    treeGit($this->alpha, 'remote', 'set-head', 'origin', 'main');
    treeGit($this->tree, 'push', '-q', 'origin', 'ring-master/'.WORKTREE_SPEC.':main');
    // The same spec left untracked on the checkout from before its job committed it.
    mkdir("{$this->alpha}/{$this->dir}", 0o755, true);
    file_put_contents("{$this->alpha}/{$this->dir}/meta.yml", "title: Export\nstatus: in-review\n");
    app(ScanWorkspaces::class)->handle();
    $project = Project::query()->where('folder', 'Alpha')->sole();

    $report = app(Janitor::class)->project($project);

    expect($report['updated'])->toBe(['dropped 1 untracked file(s) the merge brings unchanged', 'fast-forwarded main 1 commit(s)'])
        ->and(treeGit($this->alpha, 'status', '--porcelain'))->toBe('');

    treeGit($this->alpha, 'reset', '-q', '--hard', 'HEAD~1');
    File::ensureDirectoryExists("{$this->alpha}/{$this->dir}");
    file_put_contents("{$this->alpha}/{$this->dir}/meta.yml", "title: Export\nstatus: merged\n");

    $kept = app(Janitor::class)->project($project);

    expect($kept['skipped'])->toContain('checkout not updated: main could not fast-forward to origin/main')
        ->and(file_get_contents("{$this->alpha}/{$this->dir}/meta.yml"))->toContain('status: merged');
});

<?php

use App\Actions\Chores;
use App\Actions\ScanWorkspaces;
use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Livewire\Inbox;
use App\Models\Chore;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Providers\AppServiceProvider;
use App\Support\Dependabot;
use App\Support\Janitor;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
});

/**
 * Runs git in a folder, failing the test when it fails.
 */
function git(string $path, string ...$args): string
{
    $result = Process::path($path)->run(['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', '-c', 'init.defaultBranch=main', ...$args]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    return trim($result->output());
}

/**
 * Alpha as a clone of a bare origin, with: a merged branch in a clean
 * worktree, a merged branch in a dirty one, a squash-merged branch, an
 * unmerged branch, and the merged branches on origin too.
 */
function janitorRepo(string $root, string $alpha): void
{
    git($root, 'init', '--bare', '-q', 'origin.git');
    git($alpha, 'init', '-q');
    git($alpha, 'add', '-A');
    git($alpha, 'commit', '-qm', 'first');
    git($alpha, 'remote', 'add', 'origin', $root.'/origin.git');

    foreach (['merged', 'dirty', 'unmerged'] as $branch) {
        git($alpha, 'switch', '-qc', $branch, 'main');
        file_put_contents("{$alpha}/{$branch}.txt", $branch);
        git($alpha, 'add', '-A');
        git($alpha, 'commit', '-qm', $branch);
    }

    git($alpha, 'switch', '-qc', 'squashed', 'main');
    file_put_contents("{$alpha}/squashed.txt", 'squashed');
    git($alpha, 'add', '-A');
    git($alpha, 'commit', '-qm', 'squashed');

    git($alpha, 'switch', '-q', 'main');
    git($alpha, 'merge', '-q', '--no-edit', 'merged', 'dirty');
    git($alpha, 'cherry-pick', 'squashed');
    git($alpha, 'push', '-q', 'origin', 'main', 'merged', 'unmerged');
    git($alpha, 'remote', 'set-head', 'origin', 'main');
    git($alpha, 'worktree', 'add', '-q', "{$root}/Alpha--merged", 'merged');
    git($alpha, 'worktree', 'add', '-q', "{$root}/Alpha--dirty", 'dirty');
    file_put_contents("{$root}/Alpha--dirty/wip.txt", 'not committed');
}

it('removes clean merged worktrees and local branches on its own, and asks before deleting on GitHub', function () {
    janitorRepo($this->root, $this->alpha->path);
    // A GitHub remote, so the chore is raised; it is a local bare repo underneath.
    git($this->alpha->path, 'config', "url.{$this->root}/origin.git.insteadOf", 'https://github.com/acme/alpha.git');
    git($this->alpha->path, 'remote', 'set-url', 'origin', 'https://github.com/acme/alpha.git');

    $report = app(Janitor::class)->project($this->alpha);

    expect($report['worktrees'])->toBe(["{$this->root}/Alpha--merged"])
        ->and($report['skipped'])->toBe(["worktree {$this->root}/Alpha--dirty: uncommitted changes"])
        ->and($report['branches'])->toBe(['merged', 'squashed'])
        ->and($report['remote'])->toBe(['merged'])
        ->and(is_dir("{$this->root}/Alpha--merged"))->toBeFalse()
        ->and(git($this->alpha->path, 'branch', '--format=%(refname:short)'))->toBe("dirty\nmain\nunmerged");

    $chore = Chore::query()->sole();
    expect($chore)->kind->toBe(ChoreKind::RemoteBranches)->payload->toBe(['branches' => ['merged']]);

    Livewire::test(Inbox::class)->assertSee('1 merged branch left on GitHub')->call('approveChore', $chore->id);

    expect($chore->fresh())->status->toBe(ChoreStatus::Done)
        ->and(git($this->root.'/origin.git', 'branch', '--format=%(refname:short)'))->toBe("main\nunmerged");

    // Nothing merged is left: a second pass raises nothing new.
    app(Janitor::class)->project($this->alpha);
    expect(Chore::query()->count())->toBe(1);
});

it('lists what it would do on a dry run without touching anything', function () {
    janitorRepo($this->root, $this->alpha->path);

    $this->artisan('ring-master:clean', ['--dry-run' => true])
        ->expectsOutputToContain("would remove worktree Alpha: {$this->root}/Alpha--merged")
        ->expectsOutputToContain('would remove local branch Alpha: squashed')
        ->assertSuccessful();

    expect(is_dir("{$this->root}/Alpha--merged"))->toBeTrue()
        ->and(Chore::query()->count())->toBe(0);
});

it('raises a merge chore for a green Dependabot PR and a fix chore for a failing one, and acts only on approval', function () {
    $green = 'https://github.com/acme/alpha/pull/7';
    $red = 'https://github.com/acme/alpha/pull/8';
    Process::fake(function ($process) use ($green, $red) {
        $command = implode(' ', $process->command);

        return match (true) {
            str_contains($command, 'remote.origin.url') => Process::result("git@github.com:acme/alpha.git\n"),
            str_contains($command, 'pr list') => Process::result(json_encode([
                ['number' => 7, 'title' => 'Bump setup-php to 2.40.0', 'url' => $green, 'headRefName' => 'dependabot/setup-php'],
                ['number' => 8, 'title' => 'Bump guzzle to 8', 'url' => $red, 'headRefName' => 'dependabot/guzzle'],
            ])),
            str_contains($command, "pr checks {$green}") => Process::result('[{"name":"tests","bucket":"pass","link":"x"}]'),
            str_contains($command, "pr checks {$red}") => Process::result('[{"name":"tests","bucket":"fail","link":"https://github.com/acme/alpha/actions/runs/3"}]', exitCode: 1),
            default => Process::result(),
        };
    });
    mkdir($this->alpha->path.'/.git');

    expect(app(Dependabot::class)->triage())->toBe(2)
        ->and(app(Dependabot::class)->triage())->toBe(0);

    $merge = Chore::query()->where('kind', ChoreKind::DependabotMerge)->sole();
    $fix = Chore::query()->where('kind', ChoreKind::DependabotFix)->sole();

    Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), 'pr merge'));

    app(Chores::class)->approve($merge);
    app(Chores::class)->approve($fix);

    Process::assertRan(fn ($process) => $process->command === ['gh', 'pr', 'merge', $green, '--merge', '--delete-branch']);
    expect($merge->fresh()->status)->toBe(ChoreStatus::Done)
        ->and(Task::query()->where('title', 'Fix Dependabot PR: Bump guzzle to 8')->sole()->body)->toContain('actions/runs/3')->toContain('Do not merge it')->toContain('git worktree add ../Alpha--dependabot dependabot/guzzle');
});

it('puts a failed chore back in the Inbox on retry', function () {
    $chore = Chore::query()->create(['project_id' => $this->alpha->id, 'kind' => ChoreKind::DependabotMerge, 'key' => 'dependabot:1', 'title' => 'Green: Bump x', 'payload' => ['url' => 'https://github.com/acme/alpha/pull/1'], 'status' => ChoreStatus::Failed, 'result' => 'sh: line 0: exec: gh: not found', 'resolved_at' => now()]);

    Livewire::test(Inbox::class)->assertSee('gh: not found')->call('retryChore', $chore->id);

    expect($chore->fresh())->status->toBe(ChoreStatus::Open)->result->toBeNull()->resolved_at->toBeNull();
    expect(fn () => app(Chores::class)->retry($chore->fresh()))->toThrow(ValidationException::class);
});

it('adds runner.path folders missing from PATH, so a web request finds gh', function () {
    $before = getenv('PATH');
    $dir = sys_get_temp_dir().'/rm-path-'.uniqid();
    mkdir($dir);
    config(['ring-master.runner.path' => $dir.':/no/such/folder']);

    (new AppServiceProvider(app()))->boot();

    try {
        expect(explode(':', (string) getenv('PATH')))->toContain($dir)->not->toContain('/no/such/folder')
            ->and($_SERVER['PATH'])->toBe(getenv('PATH'));
    } finally {
        putenv("PATH={$before}");
        $_ENV['PATH'] = $_SERVER['PATH'] = $before;
        rmdir($dir);
    }
});

it('raises no second fix chore, and queues no second task, for a Dependabot PR a task already fixes', function () {
    $red = 'https://github.com/acme/alpha/pull/8';
    $failing = 'tests';
    Process::fake(function ($process) use ($red, &$failing) {
        $command = implode(' ', $process->command);

        return match (true) {
            str_contains($command, 'remote.origin.url') => Process::result("git@github.com:acme/alpha.git\n"),
            str_contains($command, 'pr list') => Process::result(json_encode([['number' => 8, 'title' => 'Bump guzzle to 8', 'url' => $red, 'headRefName' => 'dependabot/guzzle']])),
            str_contains($command, "pr checks {$red}") => Process::result(json_encode([['name' => $failing, 'bucket' => 'fail', 'link' => 'x']]), exitCode: 1),
            default => Process::result(),
        };
    });
    mkdir($this->alpha->path.'/.git');

    expect(app(Dependabot::class)->triage())->toBe(1);
    $chore = Chore::query()->sole();
    app(Chores::class)->approve($chore);
    $task = Task::query()->sole();

    // A new failing run on the same PR: before, a new chore and so a second task.
    $failing = 'lint';
    expect(app(Dependabot::class)->triage())->toBe(0);

    $again = Chore::query()->create(['project_id' => $this->alpha->id, 'kind' => ChoreKind::DependabotFix, 'key' => 'dependabot-fix:x', 'title' => 'Failing checks: Bump guzzle to 8', 'payload' => ['url' => $red, 'title' => 'Bump guzzle to 8', 'branch' => 'b', 'failing' => []], 'status' => ChoreStatus::Open]);
    expect(app(Chores::class)->approve($again)->result)->toBe("Task #{$task->id} already fixes this PR; nothing new queued.")
        ->and(Task::query()->count())->toBe(1);
});

/**
 * Alpha as a clone of a bare origin that has moved on by one commit (pushed
 * from another clone), so Alpha is behind once it fetches.
 */
function behindRepo(string $root, string $alpha): void
{
    git($root, 'init', '--bare', '-q', 'origin.git');
    git($alpha, 'init', '-q');
    git($alpha, 'add', '-A');
    git($alpha, 'commit', '-qm', 'first');
    git($alpha, 'remote', 'add', 'origin', $root.'/origin.git');
    git($alpha, 'push', '-q', '-u', 'origin', 'main');
    git($alpha, 'remote', 'set-head', 'origin', 'main');
    git($root, 'clone', '-q', $root.'/origin.git', 'other');
    file_put_contents("{$root}/other/merged-on-github.txt", 'merged');
    git("{$root}/other", 'add', '-A');
    git("{$root}/other", 'commit', '-qm', 'merged on GitHub');
    git("{$root}/other", 'push', '-q', 'origin', 'main');
}

it('fast-forwards a checkout that is behind its base branch, so jobs build against current siblings', function () {
    behindRepo($this->root, $this->alpha->path);

    $report = app(Janitor::class)->project($this->alpha);

    expect($report['updated'])->toBe(['fast-forwarded main 1 commit(s)'])
        ->and(is_file($this->alpha->path.'/merged-on-github.txt'))->toBeTrue();
});

it('leaves a fully merged branch for the base branch, then deletes it', function () {
    behindRepo($this->root, $this->alpha->path);
    git($this->alpha->path, 'switch', '-qc', 'drop-pin');

    $report = app(Janitor::class)->project($this->alpha);

    expect($report['updated'])->toBe(['left drop-pin (merged) for main', 'fast-forwarded main 1 commit(s)'])
        ->and($report['branches'])->toBe(['drop-pin'])
        ->and(git($this->alpha->path, 'branch', '--show-current'))->toBe('main');
});

it('never updates a checkout with uncommitted changes, commits of its own, unmerged work or a session in it', function (string $setup, string $why) {
    behindRepo($this->root, $this->alpha->path);
    $alpha = $this->alpha->path;

    match ($setup) {
        'dirty' => file_put_contents("{$alpha}/composer.json", "{}\n"),
        'ahead' => (function () use ($alpha) {
            file_put_contents("{$alpha}/local.txt", 'local');
            git($alpha, 'add', '-A');
            git($alpha, 'commit', '-qm', 'local');
        })(),
        'unmerged' => (function () use ($alpha) {
            git($alpha, 'switch', '-qc', 'wip');
            file_put_contents("{$alpha}/wip.txt", 'wip');
            git($alpha, 'add', '-A');
            git($alpha, 'commit', '-qm', 'wip');
        })(),
        'busy' => Run::query()->create(['project_id' => $this->alpha->id, 'kind' => RunKind::Task, 'session' => 's', 'status' => RunStatus::Running, 'log_path' => '', 'started_at' => now()]),
    };

    $report = app(Janitor::class)->project($this->alpha);

    expect($report['updated'])->toBe([])
        ->and($report['skipped'])->toContain("checkout not updated: {$why}")
        ->and(is_file("{$alpha}/merged-on-github.txt"))->toBeFalse();
})->with([
    'uncommitted changes' => ['dirty', 'uncommitted changes'],
    'commits of its own' => ['ahead', 'main has commits of its own'],
    'unmerged work' => ['unmerged', 'on wip, which has unmerged work'],
    'a session in it' => ['busy', 'a session is working in it'],
]);

it('can be turned off', function () {
    config(['ring-master.janitor.pull' => false]);
    behindRepo($this->root, $this->alpha->path);

    expect(app(Janitor::class)->project($this->alpha)['updated'])->toBe([]);
});

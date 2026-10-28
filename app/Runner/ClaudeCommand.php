<?php

namespace App\Runner;

use App\Enums\RunKind;
use App\Models\Run;
use App\Support\Improver;
use App\Support\Planner;
use App\Support\Replays;

/**
 * The `claude -p` command line for one run: the inbox skill as the prompt
 * (or the planner's, with read-only tools),
 * stream-json output for the log, only the configured tools (anything else
 * is denied at once, never prompted), the workspace root as a working
 * directory, and RingMaster's own MCP server.
 */
final class ClaudeCommand
{
    public function __construct(private readonly Planner $planner, private readonly Improver $improver) {}

    /**
     * @return list<string>
     */
    public function for(Run $run): array
    {
        $prompt = match ($run->kind) {
            RunKind::Task => "/ring-master-inbox --task={$run->task_id} --session={$run->session}",
            RunKind::Answers => '/ring-master-inbox --answers',
            RunKind::Plan => $this->planner->prompt($run),
            RunKind::Improve => $this->improver->prompt($run),
            RunKind::Replay => "/ring-master-factory {$run->replay_of} --replay",
        };

        // Planner and analyst only read.
        /** @var list<string> $tools */
        $tools = config(in_array($run->kind, [RunKind::Plan, RunKind::Improve], true) ? 'ring-master.planner.allowed_tools' : 'ring-master.runner.allowed_tools', []);
        $model = config('ring-master.runner.model');
        // A replay's share of its evaluation's budget.
        $budget = $run->kind === RunKind::Replay
            ? (float) config('ring-master.improve.budget_usd', 20) / max(1, (int) config('ring-master.improve.replay_specs', 3))
            : config('ring-master.runner.max_budget_usd');

        return [
            (string) config('ring-master.runner.claude_bin', 'claude'),
            $prompt,
            '-p',
            '--output-format', 'stream-json',
            '--verbose',
            '--permission-mode', 'dontAsk',
            '--permission-prompts', 'none',
            '--name', "RingMaster run #{$run->id}",
            ...(is_string($model) && $model !== '' ? ['--model', $model] : []),
            ...(is_numeric($budget) ? ['--max-budget-usd', (string) $budget] : []),
            // Job worktrees sit beside the package (../Package--spec), outside its folder.
            '--add-dir', $run->project->workspace->path,
            ...($run->kind === RunKind::Replay ? ['--add-dir', Replays::dir($run)] : []),
            '--mcp-config', $this->mcpConfig(),
            '--append-system-prompt', self::RULES,
            '--allowedTools', ...$tools,
        ];
    }

    /**
     * What every unattended session must know about the allowlist, whichever
     * skill it is in: skills say it too, but not every stage repeats it.
     */
    public const string RULES = 'You run unattended under an allowlist: a tool call it does not allow is denied, never prompted. Run exactly one command per Bash call, with no `;`, `&&`, `||`, pipes or `$(...)`: a compound command is refused as a whole. Call tools by relative path (vendor/bin/...) and write scratch files inside the project, not /tmp, and never in a spec folder: it is committed with its job. When a call is refused, retry it split or another allowed way before giving up. Never switch branches, commit or stash in a package\'s main checkout: other jobs build against it. Work that is not a factory job goes in a worktree of its own beside the package (`git worktree add ../<Package>--<slug> -b <branch> origin/main`). A job\'s spec lives in its own worktree, on its job branch: `vendor/bin/ringside spec path <id>` prints where, and `vendor/bin/ringside spec commit <id> "<stage>"` commits it.';

    private function mcpConfig(): string
    {
        return (string) json_encode(['mcpServers' => ['ring-master' => [
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), 'mcp:start', 'ring-master'],
        ]]], JSON_UNESCAPED_SLASHES);
    }
}

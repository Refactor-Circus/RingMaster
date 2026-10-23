<?php

namespace App\Runner;

use App\Enums\RunKind;
use App\Models\Run;

/**
 * The `claude -p` command line for one run: the inbox skill as the prompt,
 * stream-json output for the log, only the configured tools (anything else
 * is denied at once, never prompted), and RingMaster's own MCP server.
 */
final class ClaudeCommand
{
    /**
     * @return list<string>
     */
    public function for(Run $run): array
    {
        $prompt = $run->kind === RunKind::Task
            ? "/ring-master-inbox --task={$run->task_id} --session={$run->session}"
            : '/ring-master-inbox --answers';

        /** @var list<string> $tools */
        $tools = config('ring-master.runner.allowed_tools', []);
        $model = config('ring-master.runner.model');
        $budget = config('ring-master.runner.max_budget_usd');

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
            '--mcp-config', $this->mcpConfig(),
            '--allowedTools', ...$tools,
        ];
    }

    private function mcpConfig(): string
    {
        return (string) json_encode(['mcpServers' => ['ring-master' => [
            'command' => PHP_BINARY,
            'args' => [base_path('artisan'), 'mcp:start', 'ring-master'],
        ]]], JSON_UNESCAPED_SLASHES);
    }
}

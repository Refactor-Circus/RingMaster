<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | Folders RingMaster scans for packages, comma-separated. A folder with a
    | ring-master.yml listing workspace.packages contributes itself and those
    | packages; a folder with its own composer.json is one project; any other
    | folder contributes each subfolder that has a composer.json. "~" expands
    | to your home folder. Folders added on the Workspaces page are kept in
    | the database; the ones listed here are always present and can only be
    | disabled there.
    |
    */

    'workspaces' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('RING_MASTER_WORKSPACES', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Scanning
    |--------------------------------------------------------------------------
    |
    | Spec folders are the source of truth; RingMaster mirrors them into the
    | database. An open board rescans when the last scan is older than
    | stale_after seconds, and the scheduler rescans every minute.
    |
    */

    'scan' => [
        'stale_after' => (int) env('RING_MASTER_SCAN_STALE_AFTER', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claims
    |--------------------------------------------------------------------------
    |
    | A Claude session that claims a task holds it for lease_seconds and
    | renews the lease with each heartbeat. A lease that runs out before a
    | spec exists puts the task back in the queue, so a session that dies
    | never strands work. Once the task has a spec, its status follows the
    | spec instead. A building job (whose factory workflow sends no
    | heartbeats) counts as stalled once neither its spec folder nor its
    | worktree changed for building_stall_minutes, and is resumed.
    |
    */

    'claims' => [
        'lease_seconds' => (int) env('RING_MASTER_LEASE_SECONDS', 900),
        'building_stall_minutes' => (int) env('RING_MASTER_BUILDING_STALL_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runner
    |--------------------------------------------------------------------------
    |
    | `php artisan ring-master:work` starts headless Claude Code sessions
    | (`claude -p`, on your Claude Code login) for queued tasks and for
    | answers waiting to be applied. Each session may only use the tools in
    | allowed_tools; anything else is denied at once rather than prompting.
    | A Bash command must match an entry as a whole, so sessions are told to
    | run one command per call. Sessions draw on your plan's usage, so keep concurrency low; pause the
    | runner from the Runs page. max_budget_usd caps each session (null: no
    | cap) and max_attempts is how often a task may be retried before it is
    | marked failed. Leave the runner off (the default) to work only from
    | your own sessions with /ring-master-inbox.
    |
    */

    'notifications' => [

        /*
        |----------------------------------------------------------------------
        | Notifications
        |----------------------------------------------------------------------
        |
        | Where RingMaster tells you something needs you: new questions or
        | escalations, a task that failed after its attempts, answers a run
        | could not apply. Channels (comma-separated, none by default):
        | desktop (macOS osascript or Linux notify-send, on this machine),
        | mail (to mail_to, through Laravel's mailer), and webhook (a JSON
        | POST of {"text": ...} that Slack, Discord and ntfy accept). An open
        | RingMaster tab can also show browser notifications; enable them on
        | the Settings page.
        |
        */

        'channels' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('RING_MASTER_NOTIFY', ''))))),
        'mail_to' => env('RING_MASTER_NOTIFY_MAIL'),
        'webhook_url' => env('RING_MASTER_NOTIFY_WEBHOOK'),
        'app_url' => env('RING_MASTER_URL', env('APP_URL', 'http://127.0.0.1:8000')),
    ],

    'runner' => [
        'claude_bin' => env('RING_MASTER_CLAUDE_BIN', 'claude'),
        'concurrency' => (int) env('RING_MASTER_RUNNER_CONCURRENCY', 3),
        'model' => env('RING_MASTER_RUNNER_MODEL'),
        'max_budget_usd' => env('RING_MASTER_RUNNER_MAX_BUDGET_USD'),
        'max_attempts' => (int) env('RING_MASTER_RUNNER_MAX_ATTEMPTS', 2),
        'tick_seconds' => (int) env('RING_MASTER_RUNNER_TICK_SECONDS', 5),
        'allowed_tools' => [
            'Read', 'Edit', 'Write', 'Glob', 'Grep', 'Workflow', 'Agent', 'Skill',
            'Bash(composer *)', 'Bash(vendor/bin/*)', 'Bash(php *)', 'Bash(git *)', 'Bash(gh *)',
            'Bash(node *)', 'Bash(npm *)', 'Bash(laravel *)', 'Bash(ls *)', 'Bash(mkdir *)',
            'Bash(echo *)', 'Bash(date)', 'Bash(date *)', 'Bash(pwd)', 'Bash(cd *)',
            'Bash(cat *)', 'Bash(head *)', 'Bash(tail *)', 'Bash(wc *)', 'Bash(sort *)', 'Bash(diff *)', 'Bash(jq *)',
            'mcp__ring-master',
        ],
    ],

];

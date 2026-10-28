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
    | CI
    |--------------------------------------------------------------------------
    |
    | The runner checks the PRs of jobs in review every few minutes. When
    | their checks fail, it sends the job back to the factory with the
    | failing jobs to read, up to auto_fix_attempts times per job (0: never);
    | after that, the failure waits for you in the Inbox.
    |
    */

    'ci' => [
        'auto_fix_attempts' => (int) env('RING_MASTER_CI_AUTO_FIX', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Janitor
    |--------------------------------------------------------------------------
    |
    | Every every_minutes the runner tidies each project's checkout: it
    | removes clean worktrees and deletes local branches whose work is on the
    | base branch, and raises a chore in the Inbox for merged branches left
    | on GitHub, deleted only once you approve. It also triages open
    | Dependabot PRs: green ones become a chore to merge, failing ones a
    | chore to queue a fix. Nothing on GitHub changes without your OK.
    | Folders in skip (comma-separated) are left alone.
    | `php artisan ring-master:clean --dry-run` shows what it would do.
    |
    */

    'janitor' => [
        'enabled' => (bool) env('RING_MASTER_JANITOR', true),
        'every_minutes' => (int) env('RING_MASTER_JANITOR_EVERY', 60),
        'dependabot' => (bool) env('RING_MASTER_DEPENDABOT', true),
        'skip' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('RING_MASTER_JANITOR_SKIP', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Planner
    |--------------------------------------------------------------------------
    |
    | The portfolio planner is a read-only session over a whole workspace: it
    | reads each package's roadmap, standards feedback and post-mortems and
    | suggests up to max_suggestions jobs, which wait in the Inbox for you.
    | Start it from the Runs page, or set every_days to run it on a schedule
    | (null: only when you ask). It may use only allowed_tools.
    |
    */

    'planner' => [
        'every_days' => env('RING_MASTER_PLANNER_EVERY_DAYS'),
        'max_suggestions' => (int) env('RING_MASTER_PLANNER_MAX', 5),
        'allowed_tools' => ['Read', 'Glob', 'Grep', 'mcp__ring-master'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Night shift and the morning briefing
    |--------------------------------------------------------------------------
    |
    | Between start and end (local time; a window may cross midnight) the
    | runner works the queue on a budget of its own: once the night's runs
    | cost budget_usd, nothing new starts until the window ends (null: no
    | cap). concurrency sets sessions at once during the window (null: the
    | usual). At briefing_at the runner makes the morning briefing (what
    | shipped, PRs to review ranked by risk, what needs you, what it cost)
    | and notifies you; null: only when you ask on the Briefing page.
    |
    */

    'night_shift' => [
        'enabled' => (bool) env('RING_MASTER_NIGHT_SHIFT', true),
        'start' => env('RING_MASTER_NIGHT_START', '22:00'),
        'end' => env('RING_MASTER_NIGHT_END', '07:00'),
        'budget_usd' => env('RING_MASTER_NIGHT_BUDGET_USD', 50),
        'concurrency' => env('RING_MASTER_NIGHT_CONCURRENCY'),
        'briefing_at' => env('RING_MASTER_BRIEFING_AT', '07:30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Decision precedent
    |--------------------------------------------------------------------------
    |
    | Every architectural answer you give is kept. A new question whose words
    | overlap a past one's by at least min_similarity (0 to 1) shows that
    | answer as a suggestion you can accept with one click, and sessions are
    | told about it before they ask. A precedent is never applied without you.
    |
    */

    'precedent' => [
        'min_similarity' => (float) env('RING_MASTER_PRECEDENT_SIMILARITY', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signals
    |--------------------------------------------------------------------------
    |
    | On each janitor pass the runner reads, through gh, each GitHub
    | project's open issues, checks that fail now and then on the base branch,
    | and Dependabot PRs open for over a week. The planner suggests jobs from
    | them and the briefing lists new ones. Read-only.
    |
    */

    'signals' => [
        'enabled' => (bool) env('RING_MASTER_SIGNALS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Factory improvements
    |--------------------------------------------------------------------------
    |
    | The factory analyst reads recent jobs' evidence and proposes up to
    | max_proposals changes to the factory per run; start it on the Factory
    | page, or every every_days days (null: only when you ask). Evaluating an
    | accepted Ringside change replays up to replay_specs recent finished
    | jobs against its branch, in throwaway checkouts, capped at budget_usd
    | for the whole evaluation. Nothing is pushed by a replay.
    |
    */

    'improve' => [
        'every_days' => env('RING_MASTER_IMPROVE_EVERY_DAYS'),
        'max_proposals' => (int) env('RING_MASTER_IMPROVE_MAX', 3),
        'replay_specs' => (int) env('RING_MASTER_REPLAY_SPECS', 3),
        'budget_usd' => (float) env('RING_MASTER_REPLAY_BUDGET_USD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone actions
    |--------------------------------------------------------------------------
    |
    | Notifications can carry buttons (accept a proposed job, approve a chore,
    | answer a question, review low-risk PRs). Each is a signed link that
    | works once, only by POST, for ttl_hours. They point at
    | notifications.app_url: set it to an address only your devices reach
    | (Tailscale, your home network). RingMaster has no login; never expose
    | it to the internet.
    |
    */

    'phone' => [
        'actions' => (bool) env('RING_MASTER_PHONE_ACTIONS', true),
        'ttl_hours' => (int) env('RING_MASTER_PHONE_TTL_HOURS', 24),
    ],

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
        | mail (to mail_to, through Laravel's mailer), webhook (a JSON POST
        | of {"text": ...} that Slack and Discord accept) and ntfy (a push to
        | your phone with action buttons; ntfy_url is the topic's URL, such as
        | https://ntfy.sh/my-secret-topic, ntfy_token its access token). An open
        | RingMaster tab can also show browser notifications; enable them on
        | the Settings page.
        |
        */

        'channels' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('RING_MASTER_NOTIFY', ''))))),
        'mail_to' => env('RING_MASTER_NOTIFY_MAIL'),
        'webhook_url' => env('RING_MASTER_NOTIFY_WEBHOOK'),
        'ntfy_url' => env('RING_MASTER_NOTIFY_NTFY'),
        'ntfy_token' => env('RING_MASTER_NOTIFY_NTFY_TOKEN'),
        'app_url' => env('RING_MASTER_URL', env('APP_URL', 'http://127.0.0.1:8000')),
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
    | The Runs page can change concurrency without a restart. No new session
    | starts while the one-minute load average per CPU is above max_load
    | (null: no cap), or for limit_backoff_minutes after a run hits a usage
    | or rate limit; such a run, like one that never started, does not use
    | up one of the task's attempts.
    |
    | path lists folders (colon-separated) added to PATH for every command
    | RingMaster runs, when they exist and are missing: a web server such as
    | PHP-FPM often starts without the shell's PATH, so gh and claude would
    | not be found when you approve a chore from a page.
    |
    */

    'runner' => [
        'claude_bin' => env('RING_MASTER_CLAUDE_BIN', 'claude'),
        'path' => env('RING_MASTER_PATH', '/opt/homebrew/bin:/usr/local/bin'),
        'concurrency' => (int) env('RING_MASTER_RUNNER_CONCURRENCY', 3),
        'model' => env('RING_MASTER_RUNNER_MODEL'),
        'max_budget_usd' => env('RING_MASTER_RUNNER_MAX_BUDGET_USD'),
        'max_attempts' => (int) env('RING_MASTER_RUNNER_MAX_ATTEMPTS', 2),
        'tick_seconds' => (int) env('RING_MASTER_RUNNER_TICK_SECONDS', 5),
        'max_load' => env('RING_MASTER_RUNNER_MAX_LOAD', 4.0),
        'limit_backoff_minutes' => (int) env('RING_MASTER_RUNNER_LIMIT_BACKOFF', 15),
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

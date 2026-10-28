# Getting started

## Requirements

- PHP 8.5 with `pcntl` and `posix` (the runner uses them to detach sessions and handle Ctrl+C; without them it still works, but Ctrl+C reaches the sessions too).
- Composer, Node and npm (for the assets).
- [Claude Code](https://claude.com/claude-code) logged in (`claude` on your `PATH`). The runner uses your Claude Code login; no API key is needed.
- [GitHub CLI](https://cli.github.com) logged in (`gh auth status`) for PR checks, merge detection and Dependabot triage. Without it those features stay quiet.
- Projects that use [Ringside](https://github.com/Refactor-Circus/Ringside) 0.5 or newer.

## Install

```bash
composer setup        # composer install, .env, app key, migrations (SQLite), npm install, npm run build
```

`composer.json` installs Ringside from GitHub, as CI does. To work against your own Ringside checkout instead (changes there apply here at once), run `composer local:install` (or `local:update`): it writes a gitignored `composer.local.json` that symlinks the checkout, found beside this app, in `$RING_MASTER_PACKAGES`, or in `~/Projects/packages`.

## Serve it

Any of:

```bash
php artisan serve     # http://127.0.0.1:8000
composer dev          # server, queue, logs and Vite together
```

or let Herd or Valet serve the folder (for example `https://ringmaster.test`). There is no login: keep it bound to your own machine.

Set `RING_MASTER_URL` (or `APP_URL`) to the address you use, so links in notifications point at it.

## Add workspaces

A workspace is a folder RingMaster scans. Either list them in `.env` (comma-separated, `~` expands):

```dotenv
RING_MASTER_WORKSPACES=~/Projects/packages,~/Projects/apps
```

or add them on the **Workspaces** page. See [Concepts](concepts.md#workspaces-and-projects) for how a folder becomes projects.

Scanning happens on its own: an open page rescans when the last scan is older than `RING_MASTER_SCAN_STALE_AFTER` seconds, the runner rescans on each tick, and the scheduler runs `ring-master:scan` every minute if you run `php artisan schedule:work` (or a cron entry for `schedule:run`).

## Connect Claude Code

Register RingMaster's MCP server once, for every project:

```bash
claude mcp add --scope user ring-master -- php ~/Herd/RingMaster/artisan mcp:start ring-master
```

It runs over stdio and Claude Code starts it on demand, so the web server does not need to be up for it.

Then point each project (or a workspace root) at the app in its `ring-master.yml`:

```yaml
board: { app: ~/Herd/RingMaster }
```

and run `vendor/bin/ringside sync` there. Sessions in that folder now start with a one-line summary ("RingMaster: 2 queued, 1 need you…"), and `/ring-master-inbox` works through the queue. See [MCP server](mcp.md).

## Start the runner (optional)

```bash
php artisan ring-master:work
```

It works the queue with headless sessions until you stop it. Without it, RingMaster is a board and inbox you work from your own sessions with `/ring-master-inbox`. Start low:

```dotenv
RING_MASTER_RUNNER_CONCURRENCY=1
RING_MASTER_RUNNER_MAX_BUDGET_USD=5
```

Sessions draw on your Claude plan's usage. See [Runner](runner.md).

## Turn on notifications (optional)

```dotenv
RING_MASTER_NOTIFY=desktop
```

See [Notifications](notifications.md) for mail and webhooks.

## Your first job

1. Open **Tasks**, choose **New task**, pick a project, describe an idea, and queue it. Or press ⌘K anywhere, type the idea and press Enter.
2. With the runner up, the task is claimed within a few seconds. Its page shows the timeline, the runs and their logs.
3. When the job needs you, the Inbox badge counts it. Answer there; the runner applies the answer and the job resumes.
4. When the PR is open the job sits **In review**. Merge it yourself on GitHub; RingMaster sees the merge within five minutes and a session runs the post-mortem.

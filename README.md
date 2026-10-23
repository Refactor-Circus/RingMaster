# RingMaster

The board for [Ring Master](../packages/docs/agent/product/ring-master.md) jobs: every spec across your workspaces in one place, read from the spec folders that [Ringside](https://github.com/Refactor-Circus/Ringside) writes. It also holds a task queue and an inbox for the questions that need you, and an MCP server Claude sessions pick work up from.

RingMaster is a local, single-user app. It reads your folders and never writes to them; spec folders stay the source of truth, and the database mirrors them.

## Run it

```bash
composer setup                 # install, .env, key, migrate, build assets
php artisan serve              # http://127.0.0.1:8000
```

Or `composer dev` for the server, queue, logs and Vite together. Keep it on 127.0.0.1: there is no login.

## Workspaces

Set the folders to scan in `.env` (comma-separated, `~` expands):

```dotenv
RING_MASTER_WORKSPACES=~/Projects/packages
```

or add them on the Workspaces page. A folder whose `ring-master.yml` lists `workspace.packages` contributes itself and those packages; a folder with its own `composer.json` is one project; any other folder contributes each subfolder that has a `composer.json`.

An open board rescans when the last scan is older than `RING_MASTER_SCAN_STALE_AFTER` seconds (default 3), and `php artisan ring-master:scan` runs every minute from the scheduler (`php artisan schedule:work`).

## Pages

- **Board**: queued tasks, then one card per spec in its stage column (Ticket, Spec, Building, Needs you, In review, Done), with ticket, task progress, the latest round's verdict per reviewer and open questions. Filter by project or title.
- **Inbox**: every open architectural question (from the spec's `questions.yml`) and escalated job, with its options, the recommendation and the evidence. Answer here; the answer waits until Claude applies it to the spec folder, and can change until then. Once the question leaves the folder it shows as applied, or as answered in a session if you answered it there.
- **Tasks**: queue an idea, an existing ticket or an existing spec for a project, with a priority. Claude picks up the highest priority first, then the oldest. A task follows its spec's status once the spec exists, and its page shows its timeline and anything it waits on you for.
- **Spec**: the spec folder's documents and every round's report, rendered from markdown with raw HTML escaped. Only files inside the spec folder are readable.
- **Workspaces**: add, disable or remove folders and see what each contributes. Folders from `.env` can only be disabled.

## Claude

RingMaster's MCP server runs locally over stdio; Claude Code starts it on demand, so the web server does not have to be running. Add it once:

```bash
claude mcp add --scope user ring-master -- php ~/Projects/RingMaster/artisan mcp:start ring-master
```

and point a project or workspace root at the app in its `ring-master.yml` (`board: { app: ~/Projects/RingMaster }`, Ringside 0.5+). Sessions there start with RingMaster's one-line summary, and `/ring-master-inbox` works through the queue:

1. `pending-answers`: applies the answers you gave here, so stopped jobs resume.
2. `next-task` and `claim-task`: claims the next task (highest priority, then oldest) and ships it, unattended: any question a stage would ask you lands in the inbox.
3. `link-spec` once the spec folder exists, `heartbeat` at each stage, `release-task` when it cannot finish.

A claim is a lease (`RING_MASTER_LEASE_SECONDS`, default 900) renewed by each heartbeat; if it runs out before a spec exists, the task goes back to the queue.

## Runner

`php artisan ring-master:work` works the queue without you: it starts headless Claude Code sessions (`claude -p`, on your Claude Code login; no API key needed) in each task's folder.

- Answers you gave here go first, so stopped jobs resume; then queued tasks, highest priority first, then oldest. A job whose session stopped while its spec was being shaped, written or approved is resumed too.
- One session per folder tree at a time (a workspace root and its packages never run together), up to `RING_MASTER_RUNNER_CONCURRENCY` (default 1).
- Each session may use only `runner.allowed_tools` in `config/ring-master.php`; anything else is denied at once instead of prompting, so a session never hangs. Questions a stage would ask you land in the Inbox.
- A task gets `RING_MASTER_RUNNER_MAX_ATTEMPTS` tries (default 2) before it is marked failed. `RING_MASTER_RUNNER_MODEL` and `RING_MASTER_RUNNER_MAX_BUDGET_USD` set the model and a per-session cost cap.
- The Runs page shows whether the runner is up, pauses and resumes it, stops a run, and shows each run's log and cost. `--once` starts what can start, waits for it, and exits; Ctrl+C lets running sessions finish.

Sessions draw on your plan's usage, so start with a concurrency of 1 and a budget cap.

## Development

```bash
composer test                  # pint --test, phpstan, pest
```

PHP 8.5, Laravel 13, Livewire 4 (class components in `app/Livewire`), Flux.

# RingMaster

The board for [Ring Master](../packages/docs/agent/product/ring-master.md) jobs: every spec across your workspaces in one place, read from the spec folders that [Ringside](https://github.com/Refactor-Circus/Ringside) writes. Later phases add a task queue, an inbox for the questions that need you, and an MCP server Claude picks work up from.

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

- **Board**: one card per spec in its stage column (Ticket, Spec, Building, Needs you, In review, Done), with ticket, task progress, the latest round's verdict per reviewer and open questions. Filter by project or title.
- **Spec**: the spec folder's documents and every round's report, rendered from markdown with raw HTML escaped. Only files inside the spec folder are readable.
- **Workspaces**: add, disable or remove folders and see what each contributes. Folders from `.env` can only be disabled.

## Development

```bash
composer test                  # pint --test, phpstan, pest
```

PHP 8.5, Laravel 13, Livewire 4 (class components in `app/Livewire`), Flux.

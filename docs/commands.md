# Console commands

| Command | Does |
| --- | --- |
| `php artisan ring-master:scan` | mirrors every enabled workspace's projects and spec folders into the database, and returns expired claims to the queue. The scheduler runs it every minute |
| `php artisan ring-master:summary [--project=<path>]` | one line for a Claude session: queued tasks, questions that need you, answers to apply. Prints nothing when all three are 0. `--project` limits it to a folder (a workspace root covers its packages) |
| `php artisan ring-master:work [--once]` | the [runner](runner.md). `--once` starts what can start, waits for it to finish, and exits |
| `php artisan ring-master:clean [--dry-run] [--dependabot]` | the [janitor](janitor.md) by hand: removes merged clean worktrees and local branches and raises chores for merged branches on GitHub. `--dry-run` only lists; `--dependabot` also triages Dependabot PRs (ignored with `--dry-run`) |
| `php artisan ring-master:briefing [--quiet-notify]` | makes the [morning briefing](briefing.md) now and prints its link; `--quiet-notify` skips the notification |
| `php artisan ring-master:signals` | reads [signals](planner.md#signals) from GitHub now |
| `php artisan mcp:start ring-master` | the [MCP server](mcp.md) over stdio; Claude Code starts it |
| `php artisan schedule:work` | runs the scheduler in the foreground (the minutely `ring-master:scan`). Or add the usual `* * * * * php artisan schedule:run` cron entry |

## Composer scripts

| Script | Does |
| --- | --- |
| `composer setup` | install, `.env`, app key, migrations, npm install, asset build |
| `composer dev` | server, queue, logs and Vite together |
| `composer test` | clear config, `pint --test`, `phpstan`, Pest |
| `composer lint` / `composer lint:check` | Pint, fixing / checking |
| `composer types:check` | PHPStan |

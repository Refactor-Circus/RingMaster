# RingMaster

The control room for [Ring Master](https://github.com/Refactor-Circus/Ringside) jobs. Every spec across your workspaces on one board, read from the spec folders [Ringside](https://github.com/Refactor-Circus/Ringside) writes, plus:

- a **task queue** of ideas, tickets and specs, with priorities and a **job graph** (tasks that wait on other tasks, across packages);
- an **inbox** for everything that needs you: architectural questions, escalations, failing CI, proposed follow-up jobs, and chores on GitHub;
- a **runner** that works the queue with headless Claude Code sessions, classes their failures, backs off on usage limits and auto-fixes failing PR checks;
- a **janitor** that cleans up merged worktrees and branches and triages Dependabot PRs;
- a **night shift** that works the queue overnight on a budget, and a **morning briefing** that ranks PRs by risk and reviews the low-risk ones in one click;
- **campaigns**: one change across many packages, landing in dependency order;
- **decision precedent**: your past architectural answers suggested for questions like them;
- a **portfolio planner** that reads every package's roadmap and GitHub signals (issues, flaky checks) and suggests jobs;
- a **self-improving factory**: an analyst proposes changes to the factory from the evidence of past jobs, and replays recent jobs against them before you adopt one;
- **phone actions**: notification buttons (ntfy) that accept, approve or answer, through signed single-use links;
- a **Workflow** page showing each project's stages, factory steps, models and last week's metrics;
- an **MCP server** Claude sessions take work from.

It is a local, single-user app with no login. Spec folders stay the source of truth; nothing merges on its own, and nothing changes on GitHub without your OK.

## Quick start

```bash
composer setup                                        # install, .env, key, migrate, build assets
echo 'RING_MASTER_WORKSPACES=~/Projects/packages' >> .env
php artisan serve                                     # or let Herd or Valet serve it
claude mcp add --scope user ring-master -- php "$PWD/artisan" mcp:start ring-master
php artisan ring-master:work                          # optional: the runner
```

Then point each project at the app in its `ring-master.yml` (`board: { app: ~/Herd/RingMaster }`) and run `vendor/bin/ringside sync` there.

## Documentation

| | |
| --- | --- |
| [Getting started](docs/getting-started.md) | install, workspaces, Claude Code, the runner, your first job |
| [Concepts](docs/concepts.md) | workspaces, projects, specs, tasks, attention items, runs, chores |
| [Pages](docs/pages.md) | every screen and action |
| [Tasks and the job graph](docs/tasks.md) | queueing, statuses, claims, attempts, dependencies, proposed jobs |
| [Inbox](docs/inbox.md) | decisions, escalations, proposed jobs, chores |
| [Runner](docs/runner.md) | scheduling, allowlist, failure classes, back-off, concurrency, recovery |
| [Pull requests and CI](docs/pull-requests.md) | merge detection and CI auto-fix |
| [Janitor and Dependabot](docs/janitor.md) | branch and worktree cleanup, Dependabot triage |
| [Night shift and briefing](docs/briefing.md) | the overnight budget, the morning briefing, risk, reviewing from it |
| [Campaigns](docs/campaigns.md) | one change across many packages |
| [Decision precedent](docs/precedent.md) | past answers as suggestions |
| [Portfolio planner](docs/planner.md) | suggested jobs across a workspace, and GitHub signals |
| [The factory improving itself](docs/factory.md) | the analyst, improvements, replay evaluations |
| [Phone actions](docs/phone.md) | notification buttons and how they are protected |
| [Workflow and metrics](docs/workflow.md) | the Workflow page |
| [MCP server](docs/mcp.md) | every tool, its parameters and results |
| [Console commands](docs/commands.md) | `ring-master:*` and composer scripts |
| [Configuration](docs/configuration.md) | every setting and `.env` variable |
| [Notifications](docs/notifications.md) | desktop, mail, webhook, ntfy, browser |
| [Troubleshooting](docs/troubleshooting.md) | why a task is not starting, failed runs, stuck jobs |
| [Development](docs/development.md) | code layout, data, tests |

## Development

```bash
composer test     # pint --test, phpstan, pest
```

PHP 8.5, Laravel 13, Livewire 4, Flux.

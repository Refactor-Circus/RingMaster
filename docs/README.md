# RingMaster documentation

RingMaster is the local control room for [Ring Master](https://github.com/Refactor-Circus/Ringside) jobs: a board of every spec across your workspaces, a task queue, an inbox for everything that needs you, a runner that works the queue with headless Claude Code sessions, and an MCP server those sessions talk to.

| Page | What it covers |
| --- | --- |
| [Getting started](getting-started.md) | Install, serve, add workspaces, connect Claude Code, start the runner |
| [Concepts](concepts.md) | Workspaces, projects, specs, tasks, attention items, runs, chores, and how they relate |
| [Pages](pages.md) | Every screen in the app and every action on it |
| [Tasks and the job graph](tasks.md) | Queueing work, priorities, statuses, pausing, dependencies between tasks, proposed jobs |
| [Inbox](inbox.md) | Questions, escalations, failing PR checks, proposed jobs and chores, and what each answer does |
| [Runner](runner.md) | `ring-master:work`: scheduling, the tool allowlist, failure classes, back-off, concurrency, recovery |
| [Pull requests and CI](pull-requests.md) | How merges are detected and failing checks are auto-fixed |
| [Janitor and Dependabot](janitor.md) | Merged worktree and branch cleanup, Dependabot triage |
| [Night shift and briefing](briefing.md) | The overnight budget, the morning briefing, PR risk, reviewing in one click |
| [Campaigns](campaigns.md) | One change across many packages, in dependency order |
| [Decision precedent](precedent.md) | Your past answers, suggested for questions like them |
| [Portfolio planner](planner.md) | The read-only session that suggests jobs across a workspace, and the GitHub signals it reads |
| [The factory improving itself](factory.md) | The analyst, improvement proposals, replay evaluations |
| [Phone actions](phone.md) | Notification buttons through signed single-use links |
| [Workflow and metrics](workflow.md) | The Workflow page: stages, factory steps, models and last week's numbers |
| [MCP server](mcp.md) | Every tool Claude sessions use, with parameters and results |
| [Console commands](commands.md) | Every `artisan ring-master:*` command |
| [Configuration](configuration.md) | Every setting in `config/ring-master.php` and its `.env` variable |
| [Notifications](notifications.md) | Desktop, mail, webhook, ntfy and browser notifications, and what triggers them |
| [Troubleshooting](troubleshooting.md) | Why a task is not starting, refused commands, stuck runs |
| [Development](development.md) | Code layout, tests, and how to change RingMaster itself |

## The short version

1. Ringside writes each job's spec folder (`docs/agent/specs/<id>/`) in your repos. Those folders are the source of truth.
2. RingMaster scans them into its database and shows them on the Board.
3. You queue work on the Tasks page (or with ⌘K). The runner claims it and starts `claude -p "/ring-master-inbox --task=…"` in the project.
4. Anything a job cannot decide alone (an architectural question, an escalation, failing CI, a follow-up job in another package, a branch to delete on GitHub) lands in the Inbox. Your answer is applied by the next session and the job carries on.
5. Overnight the runner works on a budget; each morning the [briefing](briefing.md) says what shipped and ranks the PRs to review.
6. Nothing merges on its own, and nothing changes on GitHub without your OK.

RingMaster is a single-user app with no login. Keep it on your own machine.

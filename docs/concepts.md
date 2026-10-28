# Concepts

## Source of truth

Spec folders in your repos are the source of truth. Ringside writes them; RingMaster reads them and mirrors them into its database. RingMaster never edits files in your projects itself (its janitor only removes merged, clean worktrees and branches). Sessions it starts do, through Ringside's skills, the same way a session you run by hand does.

A spec folder that disappears from disk disappears from the board on the next scan.

## Workspaces and projects

A **workspace** is a folder RingMaster scans. It becomes one or more **projects**:

| The folder… | Contributes |
| --- | --- |
| has a `ring-master.yml` that lists `workspace.packages` | itself (a workspace root, for workspace-level specs) plus each listed package that has a `composer.json` |
| has its own `composer.json` | just itself |
| anything else | each direct subfolder that has a `composer.json` |

Workspaces from `RING_MASTER_WORKSPACES` are always present and can only be disabled on the Workspaces page. Ones you add on the page can also be removed. A disabled workspace is not scanned, and none of its tasks are picked up.

A **workspace root** is a project too (`is_workspace`). Some things work per workspace: tasks can only wait on tasks in the same workspace, the planner runs once per workspace root, and the Workflow page's metrics for a root cover every project in it.

## Specs and stages

A **spec** is one Ring Master job: a folder `docs/agent/specs/<id>/` with `meta.yml`, `shape.md`, `spec.md`, `tasks.md`, review rounds and so on. Its `status` decides its column on the Board:

| Column | Spec statuses |
| --- | --- |
| Ticket | `ticketed` |
| Spec | `shaping`, `written`, `approved` |
| Building | `building`, `in-progress` |
| Needs you | `needs-decision`, `escalated` |
| In review | `in-review` |
| Done | `merged`, `closed`, `done`, `imported` |

The stages a job goes through (ticket, shape, write spec, spec review, tasks, factory, PR review, post-mortem) are set in each project's `ring-master.yml`; the [Workflow page](workflow.md) shows them.

## Tasks

A **task** is work you (or a session, or the planner) put in the queue for one project: an idea, an existing ticket, or an existing spec. A session claims it and runs `/ring-master-ship` on it. As soon as the session links the spec folder it created, the task follows the spec's status. See [Tasks and the job graph](tasks.md).

## Attention items

An **attention item** is something a spec waits on you for. RingMaster raises them from the spec folders and from GitHub:

| Kind | Comes from |
| --- | --- |
| Decision | an open question in the spec's `questions.yml` while its status is `needs-decision` |
| Escalation | a spec whose status is `escalated` (the reasons come from `decision.md`); a job you sent back from review; failing PR checks once auto-fix is used up |

Each moves through **open** (waiting for you) → **answered** (you answered here; Claude has not applied it yet) → **applied** (gone from the folder after you answered here). One that leaves the folder without an answer here is **closed** (answered in a session). See [Inbox](inbox.md).

## Runs

A **run** is one headless `claude -p` session the runner started. There are three kinds:

| Kind | Does |
| --- | --- |
| Task | ships one claimed task (`/ring-master-inbox --task=<id> --session=<name>`) |
| Answers | applies the answers you gave for one project (`/ring-master-inbox --answers`), so stopped jobs resume |
| Plan | the [portfolio planner](planner.md): reads a workspace and suggests jobs |

Each run keeps its stream-json log in `storage/app/private/runs/<id>.jsonl` (stderr beside it as `.jsonl.err`), its cost, its exit code, and, when it failed, a [failure class](runner.md#failure-classes) and the commands the allowlist refused.

## Chores

A **chore** is housekeeping that reaches outside your machine, so it waits for your OK in the Inbox: deleting merged branches on GitHub, merging a green Dependabot PR, or queueing a task to fix a failing one. See [Janitor and Dependabot](janitor.md).

## Briefings, campaigns, decisions, improvements, signals

| | |
| --- | --- |
| **Briefing** | a snapshot made each morning (or on request): what shipped, PRs to review ranked by risk, what needs you, what it cost. See [Night shift and briefing](briefing.md) |
| **Campaign** | one change across many packages: a task per package, linked in dependency order. See [Campaigns](campaigns.md) |
| **Decision** | an architectural answer you gave, kept as precedent. See [Decision precedent](precedent.md) |
| **Improvement** | a change to the factory the analyst proposed, with its build task and replay results. See [The factory improving itself](factory.md) |
| **Signal** | an open issue, flaky check or stale Dependabot PR on GitHub, for the planner. See [Signals](planner.md#signals) |
| **Action token** | the single-use signed link behind a notification button. See [Phone actions](phone.md) |

Run kinds now also include **Improve** (the factory analyst) and **Replay** (a finished job rebuilt against an improvement's branch).

## Activity

Everything that happens (a task queued, claimed, paused; a spec changing status; a question raised or applied; a run starting or finishing; a chore done) is recorded as an **activity**. The Board's live feed, each task's timeline and the browser notifications are built from it.

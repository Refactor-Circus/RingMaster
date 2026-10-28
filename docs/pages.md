# Pages

Every page refreshes itself by polling (the Board every 3 seconds; Livewire slows polling in background tabs), so no websocket server is needed. The navigation shows two badges: what needs you (open questions, proposed jobs and open chores) and what is queued.

## Command bar (⌘K)

Opens anywhere. Type what you want built, pick a project, and press Enter: it is queued as an idea (the first line becomes the title, the rest the body) and you land on the new task. As you type it also lists matching specs, tasks and pages to jump to.

## Briefing (`/briefing`)

The latest [morning briefing](briefing.md), or `/briefing/{id}` for an older one: stats, the PRs ready for review lowest risk first (low-risk ones ticked; **Review N ticked** approves them on GitHub or labels them reviewed, never merges), what needs you with precedents, what shipped, the runs and their cost, the next tasks and new signals. **Brief me now** makes a fresh one. While the night shift runs it shows tonight's spend.

## Board (`/`)

- **Stats**: queued tasks, specs building, things that need you, specs in review, live runs, and what runs cost in the last 24 hours.
- **Queue**: open tasks that have no spec yet, in pickup order.
- **Columns**: one card per spec in its [column](concepts.md#specs-and-stages): ticket, task-group progress, the latest round's verdict per reviewer, and open questions.
- **Live feed**: the newest activity.
- Filter by project or title; both are kept in the URL.

## Inbox (`/inbox`)

Everything waiting on you, in one place: proposed jobs, chores, open questions and escalations grouped by spec, answers waiting for Claude to apply, and the recently resolved. See [Inbox](inbox.md).

## Tasks (`/tasks`)

The queue, in pickup order, filtered to **open**, **done** (done, failed, cancelled) or **all**. **New task** opens the form:

| Field | Notes |
| --- | --- |
| Project | any project in an enabled workspace |
| Kind | Idea, Existing ticket (needs a ticket ref, such as `T-012` or `PROJ-123`), or Existing spec (pick one of the project's specs that has no open task) |
| Title, body | what to do; the body is passed to the session |
| Priority | Low, Normal, High, Urgent |
| Waits on | optionally an open or proposed task in the same workspace; it is picked up only once that one is done or merged |

## Task (`/tasks/{id}`)

- Status, priority, kind, project, its spec, attempts, who holds the claim and until when.
- **Why it is not running yet**, when it is ready but idle: no runner, runner paused, runner restarting for new code, another run in the same folder tree, at the concurrency limit, backing off after a usage limit, machine overloaded, or next in line.
- **Waits on**: the tasks it depends on, each with *stop waiting*, and a form to add another. **Waiting on it**: tasks that depend on this one.
- The spec's open questions, answerable here.
- **Runs**: its task runs, plus answers runs in its project since it was created.
- **Timeline**: its activity.
- Actions (shown when they apply):

| Action | Does |
| --- | --- |
| Accept and queue / Decline | for a proposed job (see [Inbox](inbox.md#proposed-jobs)) |
| Edit | title, body, ticket ref, priority |
| Pause | no session picks it up until you resume it; a session on it now is stopped. A task without a spec goes back to the queue, paused |
| Resume | clears the pause and any dependencies it still waits on; the runner picks it up on its next tick |
| Force restart | stops any session, clears the claim, the attempt count, the pause and the dependencies, and queues it to start again from where its spec stands |
| Cancel task | marks it cancelled; its spec folder is not touched |
| Queue again | for a cancelled or failed task |

## Spec (`/specs/{id}`)

The spec folder's documents and every review round's reports, rendered from markdown with raw HTML escaped. Only files inside the spec folder (and its round reports) can be shown. Opens on `decision.md` when the job needs you, otherwise `spec.md` or `shape.md`.

Also: its open questions and escalations (answerable here), its tasks, and for a job **in review**, a **Send back** form: write what to change and the job goes back to the factory with your note (see [Inbox](inbox.md#escalations)).

## Runs (`/runs`)

- Whether a runner is up (it checked in recently), and **Pause runner / Resume runner**.
- **Concurrency** with − and + (1 to 20). It takes effect on the next tick, without a restart, and wins over `RING_MASTER_RUNNER_CONCURRENCY` until changed again.
- Notices while the runner is **backing off** after a usage limit (until when) or the machine is **overloaded**, and during the **night shift**, tonight's spend against its budget.
- **Plan now**: starts the [portfolio planner](planner.md) for every enabled workspace.
- **Refused commands, last 7 days**: the calls the allowlist denied, grouped by the allowlist entry that would let them through, or why none should. See [Runner](runner.md#the-tool-allowlist).
- The last 50 runs: kind, project, task, status, failure class, cost, duration, with **Stop** for a running one. Plus the cost of the last 24 hours.

## Run (`/runs/{id}`)

The run's log as it streams: what Claude said, each tool call in brief, and the final result. Below it the tail of stderr, the summary, the failure class and refused commands. **Stop** ends a running one: the runner terminates it on its next tick.

## Campaigns (`/campaigns`, `/campaigns/{id}`)

The form for a new [campaign](campaigns.md) (title, what to change, workspace, packages, priority) with a live preview of the waves it lands in, and the list of campaigns with their progress. A campaign's page shows every package's task per wave, a progress bar, and **Cancel campaign**.

## Factory (`/factory`)

[Factory improvements](factory.md): **Analyse the factory**, then each proposal with its rationale, evidence and change, its build task, its replay results against the jobs' first runs, and **Accept**, **Evaluate**, **Adopt** and **Dismiss**.

## Workflow (`/workflow`)

How jobs move through a project's stages and the factory, as its `ring-master.yml` sets them, and how the runner did last week. See [Workflow and metrics](workflow.md).

## Workspaces (`/workspaces`)

Add a folder (it must exist; `~` expands), enable or disable each workspace, remove one you added (files on disk are not touched), and **Scan now**. Each workspace lists the projects it contributes and how many specs each has, plus a scan error such as "Folder not found."

## Settings (`/settings`)

Read-only view of the notification channels, the runner and claim settings, and the configured workspaces, plus:

- **Send a test notification** through the configured channels.
- **Browser notifications in this tab**: allow them (browsers only ask on https or localhost, so serve it as `https://ringmaster.test` or `127.0.0.1`). An open RingMaster tab then shows a notification when a question or escalation is raised or a task fails.

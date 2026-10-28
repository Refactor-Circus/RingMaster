# Tasks and the job graph

## Kinds

| Kind | What the session ships |
| --- | --- |
| Idea | `/ring-master-ship "<title>\n\n<body>"`: a new job from scratch |
| Existing ticket | `/ring-master-ship <ticket ref>`: a job from a ticket already in the tracker |
| Existing spec | `/ring-master-ship <spec id>`: resume a spec that already exists. Linked from the start |

## Pickup order

Highest priority first (Urgent, High, Normal, Low), then the oldest. A task is **claimable** when it is not paused, waits on no unfinished task, its workspace is enabled, and one of:

- it is queued;
- it follows a spec in an interactive stage (`ticketed`, `shaping`, `written`, `approved`) or building, and nobody holds it (you resumed it);
- its claim ran out before it had a spec;
- it is in review, its PR is merged, and nobody holds it: a session records the merge and runs the post-mortem;
- it stalled: a session linked a spec in an interactive stage and stopped sending heartbeats, or a building spec's folder and worktree have not changed for `RING_MASTER_BUILDING_STALL_MINUTES`.

## Statuses

| Status | Meaning |
| --- | --- |
| Proposed | suggested by a session or the planner; nothing runs it until you accept it |
| Queued | waiting for a session |
| Claimed | a session holds it and has no spec yet |
| Running | its spec is being ticketed, shaped, written, approved or built |
| Needs you | its spec is `needs-decision` or `escalated` |
| In review | its PR is open |
| Done | its spec is merged, closed, done or imported |
| Failed | it ran out of attempts, or a session said it cannot be done as asked |
| Cancelled | you cancelled or declined it |

Once a task has a spec, the scanner keeps its status in step with the spec's. A queued task stays queued until a session takes it, even if its spec exists.

A task can also read **Paused** (you paused it, or a session handed it back with a spec: it stopped on something a fresh session would hit again, so it waits for you to fix that and resume it) or **Blocked** (it waits on other tasks).

## Claims and leases

A session claims a task with a name of its own. The claim is a lease of `RING_MASTER_LEASE_SECONDS` (default 900), renewed by each `heartbeat`. If it runs out before the task has a spec, the task goes back to the queue. Once a spec exists, the spec's status rules instead.

A session that cannot finish calls `release-task`:

- without a spec, the task goes back to the queue;
- with a spec, the task is paused with the reason, and you are notified;
- with `failed: true`, the task is marked failed.

## Attempts

Each time the runner starts a task run, the task's attempt count goes up. When a run ends and the task is still held without a spec, it goes back to the queue, or is marked failed once it has used `RING_MASTER_RUNNER_MAX_ATTEMPTS` (default 2). A run that failed on a usage limit or never started properly does not count (see [failure classes](runner.md#failure-classes)). **Force restart** sets the count back to 0.

## The job graph

A task can wait on any number of other tasks in the same workspace, across projects. It is not claimable while it waits on a task that is not finished: status **done**, or its spec's PR **merged**.

- Add a dependency when you queue a task (**Waits on**) or on the task's page. A task cannot wait on itself, on a task in another workspace, on a task that is already finished, or on one that already waits on it, directly or through others: cycles are refused, so the queue always drains.
- **Stop waiting** on the task's page drops one dependency. **Resume** or **Force restart** drops them all.
- Once every task it waits on is finished, the runner resumes it on its next tick with a **resume note**: for each finished task, pull that project's base branch, and, if this job's install needs it, `COMPOSER=composer.local.json composer update <packages>` (never `composer local:update`, which would drop local-only dev packages). The session does what the note says before anything else.
- The task page shows both directions: what it waits on, and what waits on it. A proposed job in the Inbox shows "#X waits on it" or "starts after #X is merged".

## Proposed jobs

A session working on a task sometimes finds a job it should not do itself. It proposes one with the MCP tool `propose-task`, in either direction:

| Proposal | Who waits | Typical case |
| --- | --- | --- |
| `blocks` (the default) | the proposing task waits on the follow-up, then resumes | the job needs a fix in another package first |
| `waits_for_this` | the follow-up waits for the proposing task's PR to merge; the proposing task carries on | the factory's impact gate found a package this change breaks: its fix starts once this PR is merged |

A follow-up an open task in that project already covers is not proposed again: when its title reads like an open or proposed task's there (by the same word overlap as [precedent](precedent.md), from 0.3, the project's own name left out), that task is linked the same way instead and the session is told so. A session passes `not_duplicate` for a change that only looks alike. The planner's suggestions are refused the same way.

Cancelling a task closes its spec's unanswered and unapplied questions too, so no answers session acts for a cancelled job, and pauses any task that waited on it, with a note, rather than leaving it waiting forever.

The planner's suggestions are proposed jobs too, with no dependencies. All of them wait in the [Inbox](inbox.md#proposed-jobs) until you accept them.

Declining a follow-up that a task was waiting on pauses that task with a note saying so; resume it when it can go on.

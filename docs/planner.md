# Portfolio planner

The planner is a read-only Claude session over a whole workspace. It reads what each package says it wants next and suggests jobs, which wait in the [Inbox](inbox.md#proposed-jobs) like any proposed job. Nothing runs a suggestion until you accept it.

## Starting it

- **Plan now** on the Runs page asks for a plan run in every enabled workspace. The runner starts one per workspace root as soon as that folder tree is free.
- Set `RING_MASTER_PLANNER_EVERY_DAYS` to plan on a schedule: a workspace root whose last plan run is older than that gets one. Empty (the default): only when you ask.

The runner has to be running. A workspace needs a root project (a folder whose `ring-master.yml` lists `workspace.packages`) for the planner to run there.

## What it reads

For each package: `docs/agent/product/` (mission, roadmap, tech stack), `docs/agent/standards-feedback.md`, and the `post-mortem.md` of its newest finished specs. Also the workspace's own `docs/agent/product/`, and the open [signals](#signals) from GitHub.

## What it suggests

At most `RING_MASTER_PLANNER_MAX` jobs (default 5), each one that moves a roadmap forward, fixes a standard that feedback shows keeps being broken, follows up a post-mortem, or deals with a signal. Each has a project, a title, a body written as the task for a fresh session (what to change, where, and how to tell it is done), and a reason naming the file it read it in. The reason shows in the Inbox prefixed "Planner:".

Its prompt lists every open or proposed task in the workspace, and `suggest-task` refuses a title that matches an open task in that project (case-insensitive), so a plan run does not queue duplicates.

## Signals

On each janitor pass (`RING_MASTER_JANITOR_EVERY`, hourly) the runner reads, through `gh`, each GitHub project's:

| Signal | When |
| --- | --- |
| issue | an open issue (the newest 30), with its labels |
| flaky check | a workflow on the default branch with both passing and failing runs among the last 40, at least four runs, the latest passing |
| stale Dependabot PR | a Dependabot PR open for more than a week |

A signal not seen on a later pass is resolved; a `gh` call that fails resolves nothing. The planner's prompt lists the 40 newest open signals of the workspace and may suggest a job for one, citing its URL. The [briefing](briefing.md) lists new ones. `php artisan ring-master:signals` collects them now; `RING_MASTER_SIGNALS=false` turns them off. Nothing is written to GitHub.

## What it may do

Only `planner.allowed_tools`: `Read`, `Glob`, `Grep` and RingMaster's MCP tools. It cannot edit files or run commands, and `suggest-task` works only for a session that is a running plan run. You are notified when it is done, with how many jobs it suggested.

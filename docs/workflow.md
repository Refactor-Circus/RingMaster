# Workflow and metrics

The **Workflow** page (`/workflow`) shows, for one project at a time, how its jobs move: the stages and factory steps its `ring-master.yml` sets up, merged over Ringside's defaults. Pick the project at the top (kept in the URL). A value its `ring-master.yml` sets differently from the default is marked **set in ring-master.yml**; a project without the file shows "not found: all defaults". A file Ringside cannot parse shows its error instead.

RingMaster reads the file with Ringside's own config loader, so what you see is what the skills and the factory will do. To change it, edit `ring-master.yml` in the project and run `vendor/bin/ringside sync`.

## Stages

Run by `/ring-master-ship`, in order. Each shows its skill, its model (role, effort, and "→ opus on retry" when the role escalates), what it does, its settings, whether it is on, and how many of the project's jobs are there now.

| Stage | Skill | Jobs counted there |
| --- | --- | --- |
| Ticket | `/ring-master-ticket` | queued tasks without a spec |
| Shape | `/ring-master-shape-spec` | `ticketed`, `shaping` |
| Write spec | `/ring-master-write-spec` | |
| Spec review | `/ring-master-spec-review` | `written` |
| Tasks | `/ring-master-create-tasks` | `approved`, no `tasks.md` yet |
| Factory | `/ring-master-factory` | `approved` with tasks, `building` |
| PR review | (you) | `in-review` |
| Post-mortem | `/ring-master-post-mortem` | `merged` |

## Inside the factory

One agent per step, in the job's worktree.

- **Once**: Prepare worktree (mode, path, setup), Build groups (implementer model, max turns, test command, one at a time or in parallel up to `build.max_concurrency`), and **Integrate**: when groups of a wave were built in parallel, each in its own worktree, the integrator merges their branches into the job branch, resolves conflicts, runs the tests and commits. Integrate is on with `build.parallel` (Ringside's default since 0.9) in a per-job package.
- **Each round**: Gate (verify, format check, `ringside check`, criteria mapping), Reviewers (each with its model), Triage (dedupes findings when there is more than one; on by default since Ringside 0.9), Rework (fix from the rework threshold).
- **Variants** (on the build step): one build by default. A spec's `meta.yml` `variants: N`, or `build.variants`, builds the task list N ways at once (up to 4, each in its own worktree with its own approach and model); a judge keeps the best and review goes on from it. Escalations offer **Rebuild with variants** (`build.escalation_variants`, 3).
- **Then, once it would approve**: the **Impact gate** runs the tests of every package that uses this one against the job branch, in parallel. A package it breaks gets a proposed fix task that starts once this PR is merged ([job graph](tasks.md#proposed-jobs)). Off with `impact: { consumers: off }` or `impact: { enabled: false }`. Then the **Reviewer brief**: the briefer writes `brief.md` (what changed, why, where to look, what is tested, the risks and a risk call), which the PR starts with and the [briefing](briefing.md) ranks by.
- **Rounds**: max, min, and how many rounds without progress stop it.
- **A run ends with**: PR opened (gate green, nothing left above the rework threshold), Needs you (an architectural finding, a design question, or a change to an architectural path), or Escalated (the gate red too many rounds running, the same findings repeating, or findings left after the last round).

A step that is off shows the `ring-master.yml` setting that turns it on.

## Last 7 days

For the project, or for a workspace root, every project in its workspace:

| Metric | |
| --- | --- |
| Runs | started in the last 7 days, and how many are running now |
| Success | the share that succeeded |
| Cost | total, and per job (task runs with a task) |
| Median run | median duration of finished runs |
| CI auto-fixes | jobs sent back on their own for failing checks ([CI auto-fix](pull-requests.md#ci-auto-fix)) |
| Refused commands | calls the allowlist denied ([Runner](runner.md#the-tool-allowlist)) |
| Failed runs | per [failure class](runner.md#failure-classes) |

# Janitor and Dependabot

Every `RING_MASTER_JANITOR_EVERY` minutes (default 60) the runner tidies every git project in the enabled workspaces, except the folders in `RING_MASTER_JANITOR_SKIP`. The rule: **what stays on your machine it does on its own; what changes GitHub waits for your OK** as a [chore in the Inbox](inbox.md#chores).

## Local cleanup (automatic)

For each project with a `.git` folder:

1. `git fetch --prune origin` (when it has an origin).
2. Works out the **base branch**: origin's default (`origin/HEAD`), else `origin/main` or `origin/master`; without an origin, local `main` or `master`. No base found: the project is skipped.
3. **The checkout itself** (`RING_MASTER_JANITOR_PULL`, on by default): jobs build against their siblings' local checkouts, so a stale one breaks them. When the checkout is behind the base it is brought up to it: on the base branch, fast-forwarded; on a branch whose work is all merged, switched to the base branch and fast-forwarded (step 5 then deletes the old branch). It is left as it is, and reported when behind, if it has uncommitted tracked changes, commits of its own, unmerged work on its branch, a detached HEAD, or a session working in it. The same update also runs for a package as soon as RingMaster sees one of its PRs merged.
4. **Worktrees**: removes each linked worktree whose branch is merged into the base, **if it is clean**. A worktree with uncommitted changes is kept and reported.
5. **Local branches**: deletes each branch merged into the base, except the current branch, the base itself and any branch checked out in a worktree.
6. **Remote branches**: lists the branches on origin that are merged into the base. These are not deleted; see below.

"Merged" means every commit of the branch is on the base: either the branch is an ancestor of the base, or `git cherry` finds every one of its changes there already (squash and rebase merges). An unmerged branch is never touched.

Each project that was updated or lost worktrees or branches gets an activity entry.

## Branches on GitHub (asks first)

When a project's origin is on GitHub and has merged branches, RingMaster raises one chore for the project: "3 merged branches left on GitHub", listing them. Approving it runs `git push origin --delete <branches>`. There is only ever one open chore per project: a changed set replaces it, and none left dismisses it.

## Dependabot triage (asks first)

With `RING_MASTER_DEPENDABOT` on (default), each GitHub project's open Dependabot PRs (`gh pr list --author app/dependabot`) are checked:

| Checks | Chore | Approve does |
| --- | --- | --- |
| all passed | "Green: <title>" | merge it: `gh pr merge --merge --delete-branch`, falling back to `--squash` |
| some failed or were cancelled | "Failing checks: <title>", listing them | queue a task in that project: check the PR's branch out in a worktree of its own (never the main checkout), read the failing logs, fix what the update broke, push, and never merge |
| pending, or none ran | nothing yet | |

A chore whose PR closed, or whose checks changed, is dismissed on its own on the next pass. Approving a merge chore is the one place RingMaster merges anything, and only because you said yes to that PR.

## Run it yourself

```bash
php artisan ring-master:clean --dry-run       # list what it would remove and which remote branches are merged; touches nothing
php artisan ring-master:clean                 # do the local cleanup and raise the chores
php artisan ring-master:clean --dependabot    # also triage Dependabot PRs
```

## Settings

| `.env` | Default | |
| --- | --- | --- |
| `RING_MASTER_JANITOR` | `true` | run it from the runner at all |
| `RING_MASTER_JANITOR_EVERY` | `60` | minutes between passes |
| `RING_MASTER_JANITOR_PULL` | `true` | bring each checkout up to its base branch (step 3) |
| `RING_MASTER_DEPENDABOT` | `true` | triage Dependabot PRs on each pass |
| `RING_MASTER_JANITOR_SKIP` | empty | comma-separated project folder names to leave alone |

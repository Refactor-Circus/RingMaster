# Inbox

Agents stop here instead of guessing. The Inbox holds, in order:

1. **Proposed jobs**: follow-ups sessions found and the planner's suggestions.
2. **Chores**: housekeeping on GitHub that needs your OK.
3. **Open questions and escalations**, grouped by spec.
4. **Answered, waiting for Claude**: answers you gave that no session has applied yet.
5. **Recently resolved**.

The navigation badge counts 1 to 3.

## Decisions

An architectural question from spec review or the build, read from the spec's `questions.yml` while the spec is `needs-decision`. Each shows its options, the recommendation (marked) and the evidence. Choose an option or write your own answer, with an optional note.

When you answered a question like it before, its [precedent](precedent.md) shows above the options with **Answer the same**.

Your answer waits until a session applies it to the spec folder with `/ring-master-decide`; until then you can change it. The runner starts an answers run for the project on its own (see [Runner](runner.md#what-starts-first)). Once the question leaves `questions.yml` it shows as **applied**; one answered in a session instead shows as **in a session**.

## Escalations

| Source | Raised when | Options |
| --- | --- | --- |
| build | the factory gave up on a job (spec `escalated`; reasons from `decision.md`) | **Approve as is** (opens the PR with `/ring-master-approve`), **Send back with a note** (`/ring-master-rework`), or **Rebuild with variants**: the factory builds it again `build.escalation_variants` ways at once (3 by default), a judge keeps the best, and review goes on from there (Ringside's variants) |
| review | you sent a job back from review on its Spec page | Send back with a note |
| status | the spec's `meta.yml` status is not one Ringside knows (a session wrote `shaped`, say), so no stage would pick it up | where to go from here: shape it again, finish shaping, review the written spec, build it, or close it. RingMaster sets that status in `meta.yml` itself, at once, and the job follows it |
| ci | a PR's checks fail and [CI auto-fix](pull-requests.md#ci-auto-fix) is used up | Send back with a note (prefilled with the failing jobs) or **Leave it in review** |

Sending back always needs a note saying what to change. Approving a job whose task groups are not all built asks you to tick a box first: it would open a PR with unbuilt work. **Leave it in review** needs nothing from Claude, so it closes at once. Sending a job back also resumes its task if it was paused, so the rework can start.

## Proposed jobs

Each shows the project, the title, why it was proposed (planner suggestions start with "Planner:"), the task as written, and how it connects to other tasks ("#X waits on it", "starts after #X is merged").

- **Accept and queue** moves it to the queue. If it waits on a task, it starts once that task is finished.
- **Decline** cancels it. A task that was waiting on it is paused, with a note, until you resume it.

## Chores

Raised by the [janitor and Dependabot triage](janitor.md). Nothing changes on GitHub until you approve.

| Chore | Approve does | Example title |
| --- | --- | --- |
| merged branches | `git push origin --delete <branches>` for the listed branches | "3 merged branches left on GitHub" |
| dependabot (green) | `gh pr merge <url> --merge --delete-branch`, or `--squash` if the repo allows only that | "Green: Bump guzzle to 7.10" |
| dependabot (failing) | queues a task in that project to fix the PR on its branch and push, never merge | "Failing checks: Bump pest to 5" |

Approve asks you to confirm. **Leave it** dismisses it. A chore whose situation changed (the branches are gone, the PR closed or its checks changed) is dismissed on its own. The last five done or failed chores are listed with their result; a failed one says why, and **retry** puts it back in the Inbox to approve again.

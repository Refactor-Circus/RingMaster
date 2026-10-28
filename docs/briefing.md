# Night shift and the morning briefing

Queue work in the evening, and the runner works through it overnight on a budget. In the morning one page tells you what shipped, which PRs to review (quickest first), what needs you, and what it cost.

## Night shift

Between `RING_MASTER_NIGHT_START` and `RING_MASTER_NIGHT_END` (default 22:00 to 07:00, local time; the window may cross midnight) the runner:

- stops starting sessions once the runs started in the window have cost `RING_MASTER_NIGHT_BUDGET_USD` (default $50). You are notified once ("Night shift budget spent"), and running sessions finish. The rest waits until the window ends, when the runner carries on as usual;
- runs `RING_MASTER_NIGHT_CONCURRENCY` sessions at once, if set (otherwise the usual concurrency). A concurrency you set on the Runs page still wins.

Outside the window nothing changes. The Runs page and the Briefing page show what the night has spent so far, and a task's page says when it waits for the morning. Usage limits are handled as always: the runner [backs off](runner.md#failure-classes) and the attempt is not counted.

Set `RING_MASTER_NIGHT_SHIFT=false` to turn the window off (no night budget, no night concurrency).

## The briefing

At `RING_MASTER_BRIEFING_AT` (default 07:30) the runner makes a briefing and notifies you. **Brief me now** on the Briefing page, or `php artisan ring-master:briefing`, makes one at any time. Each briefing is a snapshot covering the time since the previous one, and the last two weeks' are linked at the top.

| Section | Shows |
| --- | --- |
| Stats | shipped, PRs to review, things that need you, money spent |
| Ready for your review | every job in review, **lowest risk first**, with its risk score and reasons, the diff size, links to its PRs and the reviewer brief |
| Needs you | open questions (with the [precedent](precedent.md) for each, when there is one), and how many proposed jobs, chores and factory improvements wait |
| Shipped | jobs that reached review or were merged |
| Runs | how many, how many succeeded, the cost, last night's spend against the budget, and failures by class |
| Next in the queue | the next ten tasks |
| New signals | [signals](planner.md#signals) seen since the last briefing |

## Risk

Each job in review gets a score from 0 to 100, from what is known about it:

| Adds | When |
| --- | --- |
| 30 / 15 | more than 800 / 300 lines changed across its PRs |
| 25 | it touches a path in the project's `escalation.architectural_paths` (`composer.json`, `config/**`, migrations, contracts, `.github/**` by default) |
| 20 | none of the changed files is under `tests/` |
| 10 per round | review rounds beyond two (up to 30) |
| 15 | the factory escalated it before |
| 10 per fix | CI auto-fixes it needed |
| 10 | it spans several packages |
| 25 / 10 | the reviewer brief rates it high / medium |

Under 25 is **low**, under 50 **medium**, otherwise **high**. The diff comes from `gh pr view`, so the runner needs `gh` logged in.

## The reviewer brief

When the factory approves a job, Ringside's briefer writes `brief.md` in the spec folder: a `Risk: low|medium|high` line, then **What changed**, **Why**, **Look at** (the one to five places to check, as `path:line`), **Tested** and **Risks**. The PR body starts with it, the briefing shows it under each PR, and the Spec page lists it with the spec's other files.

## Reviewing from the briefing

Low-risk PRs are ticked for you. **Review N ticked** posts an approving review on each ticked PR (`gh pr review --approve`). GitHub does not let you approve a PR you opened yourself, and the factory opens PRs as you, so when the approval is refused RingMaster labels the PR `ring-master:reviewed` (creating the label if needed) and comments instead. The result for each PR is shown.

It never merges. Merging stays your click on GitHub.

The briefing notification carries a **Review N low-risk PRs** button when there are any (see [phone actions](phone.md)).

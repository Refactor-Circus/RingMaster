# Pull requests and CI

Every five minutes the runner checks, through `gh`, the PRs of every spec that is `in-review` and not yet known to be merged. The PRs come from the spec's `meta.yml` `pr:` (one or a list; a bare URL works). Only `https://github.com/...` PRs are checked. You need the runner running and `gh` logged in.

Nothing is ever merged by RingMaster for a job. Merge on GitHub yourself.

## Merges

When every one of a spec's PRs reports `MERGED`, RingMaster records it (`pr_merged_at`). From then on:

- the spec's task becomes claimable again, and a session marks the spec merged and runs the post-mortem;
- tasks that wait on this one count it as finished and resume ([job graph](tasks.md#the-job-graph)).

Spec folders only learn of a merge when a session checks, so this is what moves a merged job on.

## CI auto-fix

When some of a PR's checks fail (`gh pr checks`, bucket `fail`), RingMaster collects the failing set: each check's workflow, name and link.

- While the spec has used fewer than `RING_MASTER_CI_AUTO_FIX` fixes (default 2), the job is **sent back on its own**: it goes to the factory as a rework with this note:

  ```text
  The PR's CI checks fail. Read the failing job logs (gh run view <run> --log-failed) and fix the cause:
  - tests / PHP 8.5 – Laravel 13: https://github.com/…/actions/runs/…
  ```

  A closed item records the attempt ("2 PR checks failed: sent back on its own (attempt 1)") under the failing set's key, so the same failures are never sent back twice while the rework waits to start. The activity feed and the Workflow page count these auto-fixes.
- Once the fixes are used up, the failing set becomes an [escalation in the Inbox](inbox.md#escalations): send it back (the note is prefilled with the failing jobs; edit it first) or leave it in review.
- A new failing set replaces an unanswered older one; checks that go green again close it.

Set `RING_MASTER_CI_AUTO_FIX=0` to always ask you instead.

Pending checks are left alone until they finish.

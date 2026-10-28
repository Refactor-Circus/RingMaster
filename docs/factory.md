# The factory improving itself

Every failed run, refused command, red gate, escalation, rework round and post-mortem is evidence about the factory: Ringside's skills and workflow, and RingMaster's runner settings. The **factory analyst** reads that evidence and proposes changes to the factory. A Ringside change is built on a branch and **replayed** against recent real jobs before you adopt it, so you decide with before-and-after numbers in front of you.

## The analyst

**Analyse the factory** on the Factory page (`/factory`) asks for an analyst run in every enabled workspace; `RING_MASTER_IMPROVE_EVERY_DAYS` runs it on a schedule. The runner starts it at the workspace root, read-only (`planner.allowed_tools`), with:

- the evidence of the last 30 days: failed runs by class, refused commands grouped by fix, CI auto-fixes, review rounds per job, the latest escalations and their reasons;
- the post-mortems and standards feedback to read;
- where the factory lives: the workspace's Ringside checkout (its skills, workflow and defaults) and RingMaster's `config/ring-master.php`;
- the proposals already open, so it does not repeat them.

It proposes at most `RING_MASTER_IMPROVE_MAX` (default 3) with the MCP tool [`propose-improvement`](mcp.md#propose-improvement): a title, a target (`ringside`, `allowlist` or `config`), the rationale, the evidence, and exactly what to change. You are notified when it is done.

## From proposal to adoption

| Status | Means | You can |
| --- | --- | --- |
| Open | proposed | **Accept: build it** (a Ringside change), **Adopt (I made the change)** (an allowlist or config change: RingMaster never edits its own config; make the change yourself), Dismiss |
| Building | a High-priority task in the Ringside checkout builds it as a normal job with its own PR | Adopt, Dismiss; **Evaluate** once its PR is open |
| Evaluating | replays are running | wait |
| Evaluated | each replay's result sits next to the job's first run | Evaluate again, Adopt, Dismiss |
| Adopted | you kept it. Its PR is yours to merge, like any other | |
| Dismissed | | |

You are notified when an improvement's branch is ready to evaluate and when an evaluation finishes.

## Replays

**Evaluate** picks up to `RING_MASTER_REPLAY_SPECS` (default 3) recent finished jobs (merged or done, with tasks) in the workspace's packages, not Ringside itself, and replays each, one at a time per package:

1. A throwaway folder `storage/app/private/replays/<run>/`.
2. Ringside checked out there at the improvement's branch (fetched from origin if it is not local).
3. The package checked out there, detached, at the parent of the commit that added the job's `spec.md`: the code as it was when the job started.
4. The job's `spec.md`, `shape.md`, `references.md`, `standards.md`, `tasks.md` (unticked) and `meta.yml` (status approved, without its PR and branch) copied in.
5. The package installed from its `composer.local.json` (or `composer.json`) with Ringside resolved to the branch checkout and every other path repository pointing at the real sibling checkout, then `ringside sync --force`.
6. `claude -p "/ring-master-factory <spec> --replay"` in that checkout, with the runner's allowlist and a budget of `RING_MASTER_REPLAY_BUDGET_USD / RING_MASTER_REPLAY_SPECS` (default $20 / 3).

Ringside's replay mode builds and reviews on its own `--replay` branch and worktree, never pushes, never opens or updates a PR, never proposes tasks and never calls RingMaster, and ends by writing `replay.json` (`status`, `rounds`, `gate_red_rounds`, `findings_left`, `nits`, `variants_winner`, `reasons`).

The Factory page then shows, per job: the replay's status against how the job ended the first time (approved or escalated), its rounds against the first run's, and its cost against the first run's. A replay whose checkout could not be built, or that wrote no `replay.json`, shows "no result" with the reason.

Afterwards the throwaway folder is deleted, the repositories' worktree lists pruned, and the replay's `ring-master/<spec>…replay…` branches deleted. The job's real branches are never touched.

## Costs

Analyst runs read only and cost little. A replay is a full factory run: an evaluation of three jobs costs up to the replay budget ($20 by default). Nothing replays unless you press **Evaluate**.

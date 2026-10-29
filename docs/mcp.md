# MCP server

RingMaster's MCP server (`ring-master`, over stdio) is how Claude sessions take work from the queue, report progress and propose jobs. Register it once:

```bash
claude mcp add --scope user ring-master -- php ~/Herd/RingMaster/artisan mcp:start ring-master
```

The runner passes it to its own sessions with `--mcp-config`, so runner sessions need nothing more. Ringside's `/ring-master-inbox` skill drives these tools; you rarely call them by hand.

## How a session uses it

1. `pending-answers` for its folder: apply each answer to its spec folder first (`/ring-master-decide`, `/ring-master-approve` or `/ring-master-rework`), so stopped jobs resume.
2. `next-task`, then `claim-task` with a stable session name; run `/ring-master-ship` with the task's `ship_input` in the task's project folder.
3. `link-spec` as soon as the spec folder exists. `heartbeat` between stages, or the claim expires.
4. `release-task` with the reason when it cannot finish.
5. `propose-task` for a separate job the task needs (then `release-task`), or one that must wait for this task (`waits_for_this`). Never start that job itself.
6. A claimed task with a `resume_note`: do what it says first.
7. A planner session only reads, and suggests jobs with `suggest-task`. A factory analyst session only reads, and proposes changes to the factory with `propose-improvement`.
8. Before asking an architectural question, `precedents` with it: when the user answered one like it, recommend the matching option and cite the precedent first in the evidence. Still ask.

Sessions never answer an architectural question themselves, and never merge.

`project_path` arguments are absolute paths; a workspace root covers its packages.

## Tools

### `queue-status` (read-only)

How much work waits in a folder.

| Parameter | |
| --- | --- |
| `project_path` | optional; omit for everything |

Returns `queued`, `needs_user`, `answers_to_apply`, and `next`: the next five claimable tasks in pickup order.

### `pending-answers` (read-only)

Answers you gave in RingMaster that are not in the spec folders yet.

| Parameter | |
| --- | --- |
| `project_path` | required |

Returns `specs`: for each, `project_path`, `spec`, `spec_dir`, `spec_path` (the folder, absolute: in the job worktree while the job is open), `spec_status`, `task_ids` (the spec's open tasks), and `answers` (`kind`, `question`, `answer`, `note`, `source`, `answered_at`). RingMaster marks each applied once it leaves the folder.

### `next-task` (read-only)

The task to pick up next in a folder, without claiming it, or `null`.

| Parameter | |
| --- | --- |
| `project_path` | required |

### `claim-task`

Claims a task. The claim is a lease ([Tasks](tasks.md#claims-and-leases)). Fails when the task is not claimable, for example another session holds it.

| Parameter | |
| --- | --- |
| `task_id` | required |
| `session` | required: a name for this session, the same on every call |

### `heartbeat`

Renews the claim, optionally adding a progress line to the task's timeline.

| Parameter | |
| --- | --- |
| `task_id`, `session` | required |
| `message` | optional, up to 500 characters |

### `link-spec`

Records the spec folder the session created; from then on the task follows the spec's status. Rescans the workspace.

| Parameter | |
| --- | --- |
| `task_id`, `session` | required |
| `spec` | required: the spec id or its folder, e.g. `2026-10-24-0900-exports` or `docs/agent/specs/2026-10-24-0900-exports` |

### `release-task`

Hands a claimed task back.

| Parameter | |
| --- | --- |
| `task_id`, `session`, `reason` | required; always say why |
| `failed` | `true`: it cannot be done as asked (marked failed). `false` (default): a task without a spec goes back to the queue; one with a spec is paused until you resume it, so the reason should say what you must fix |

### `propose-task`

Proposes a follow-up job in another (or the same) project of the workspace. It waits in the Inbox until you accept it.

| Parameter | |
| --- | --- |
| `task_id` | required: the claimed task, or (applying answers) one of the spec's `task_ids` from `pending-answers` |
| `session` | required |
| `project` | required: folder name of the project the job is for, e.g. `Atrium` |
| `title` | required |
| `body` | required: what to change and why, written as the task for a fresh session |
| `reason` | required: why the task needs it, shown to you |
| `blocks` | default `true` unless `waits_for_this`: the proposing task waits on the follow-up and resumes once it is done |
| `waits_for_this` | `true`: the follow-up waits for the proposing task to be done or merged; the proposing task does not wait. For fixes in packages a change breaks |
| `not_duplicate` | `true`: propose it even though an open task in that project has a title like it. Without it, that task is linked instead and returned as `already_covered_by` |

Returns the proposed task.

### `suggest-task`

The planner's way to suggest a job. Only a session that is a running plan run may call it.

| Parameter | |
| --- | --- |
| `session` | required: the session name from the planner's prompt |
| `project` | required: package folder name |
| `title` | required; refused if that project has an open task with the same title (case-insensitive) |
| `body` | required: what to change, where, and how to tell it is done |
| `reason` | required: why now, naming what it read it in |

### `precedents` (read-only)

Past architectural answers to questions like this one, closest first (up to five, similarity 0.3 or more). See [Decision precedent](precedent.md).

| Parameter | |
| --- | --- |
| `question` | required: the question about to be asked |
| `project_path` | optional; precedents come from every project |

Returns `{"precedents": [{"question", "answer", "note", "project", "decided_at", "similarity"}]}`.

### `propose-improvement`

The factory analyst's way to propose a change to the factory. Only a session that is a running analyst run may call it, at most `improve.max_proposals` times per run. See [The factory improving itself](factory.md).

| Parameter | |
| --- | --- |
| `session` | required: the session name from the analyst's prompt |
| `title` | required; refused if the same title is already proposed and not dismissed |
| `target` | required: `ringside` (a skill, workflow or default), `allowlist` (`runner.allowed_tools`) or `config` (another RingMaster setting) |
| `rationale` | required: what goes wrong today and why this fixes it |
| `evidence` | required: the runs, specs, post-mortems or files it rests on |
| `change` | required: exactly what to edit, written as the task for a fresh session |

## Task payload

Every tool that returns a task returns:

```json
{
  "id": 12,
  "kind": "idea",
  "title": "…",
  "body": "…",
  "ticket_ref": null,
  "priority": "Normal",
  "status": "claimed",
  "claimed_by": "runner-ab12cd",
  "lease_expires_at": "2026-10-27T10:15:00+00:00",
  "project": { "folder": "Keystone", "path": "/…/Keystone", "is_workspace": false },
  "spec": { "key": "2026-10-27-0900-exports", "dir": "docs/agent/specs/2026-10-27-0900-exports", "path": "/Users/you/Projects/packages/Atrium--2026-10-27-0900-exports/docs/agent/specs/2026-10-27-0900-exports", "status": "building" },
  "ship_input": "2026-10-27-0900-exports",
  "resume_note": null,
  "depends_on": [9]
}
```

`ship_input` is the argument for `/ring-master-ship`: the spec id once there is a spec, the ticket ref for a ticket task, otherwise the title and body.

## Session summary

Projects whose `ring-master.yml` sets `board.app` start each session with (Ringside's session-start hook runs it):

```bash
php artisan ring-master:summary --project=<project path>
```

which prints one line when anything waits ("RingMaster: 2 queued, 1 need you, 0 answer(s) to apply. Run /ring-master-inbox to work through them.") and nothing otherwise.

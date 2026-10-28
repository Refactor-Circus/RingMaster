# Runner

```bash
php artisan ring-master:work          # until you stop it
php artisan ring-master:work --once   # start what can start, wait for it, exit
```

The runner works the queue without you. It starts headless Claude Code sessions (`claude -p`, on your Claude Code login) in each job's project folder and watches them. Leave it off to work only from your own sessions with `/ring-master-inbox`.

## Each tick

Every `RING_MASTER_RUNNER_TICK_SECONDS` (default 5) the runner:

1. Records a heartbeat, so the Runs page knows it is up.
2. Rescans the workspaces if the last scan is stale, and returns expired claims to the queue.
3. Every five minutes, checks the PRs of jobs in review ([Pull requests and CI](pull-requests.md)).
4. Resumes tasks whose dependencies are all finished ([job graph](tasks.md#the-job-graph)).
5. Marks finished [campaigns](campaigns.md) done, and picks up the branch of [factory improvements](factory.md) being built.
6. Every `RING_MASTER_JANITOR_EVERY` minutes, runs the [janitor and Dependabot triage](janitor.md) and collects [signals](planner.md#signals).
7. Once a day after `RING_MASTER_BRIEFING_AT`, makes the [morning briefing](briefing.md).
8. Reaps finished sessions and terminates the ones you asked to stop.
9. Starts new sessions, unless it is paused, stopping, waiting to restart on new code, backing off, or the [night shift](briefing.md#night-shift) budget is spent, and while it is under its concurrency limit and the machine is not overloaded.

A tick that throws (a locked database, a scan error) is logged and retried on the next one; it never takes the running sessions down.

## What starts first

1. **Answers runs**: a project with answers you gave here that are newer than its last answers run. It applies them (`/ring-master-inbox --answers`) so stopped jobs resume. A run that could not apply them is not retried until you answer again.
2. **Planner runs**: a workspace root you asked to plan, or whose last plan is older than `RING_MASTER_PLANNER_EVERY_DAYS` ([planner](planner.md)).
3. **Analyst runs**: a workspace root you asked to analyse on the Factory page, or whose last analysis is older than `RING_MASTER_IMPROVE_EVERY_DAYS` ([factory](factory.md)).
4. **Replay runs**: the next job an improvement's evaluation waits to replay, in a throwaway checkout ([replays](factory.md#replays)).
5. **Task runs**: claimable tasks in [pickup order](tasks.md#pickup-order). The runner claims the task under a fresh session name (`runner-xxxxxx`), counts an attempt, and starts `/ring-master-inbox --task=<id> --session=<name>`.

**One session per folder tree**: a project is skipped while another run works in it, in a folder inside it, or in a folder that contains it. A workspace root and its packages never run at once.

## Limits

When fewer sessions run than the limit, the Runs page says why under **Why N of M**: what holds the runner (paused, busy machine, back-off, night budget), and every open job that is not running grouped by its reason: waiting on other tasks, needs you, your answer waiting to be applied, paused, its folder busy with another session, or ready.

| Limit | Setting | Notes |
| --- | --- | --- |
| Sessions at once | `RING_MASTER_RUNNER_CONCURRENCY` (default 3) | The Runs page's − and + override it live, from 1 to 20, until changed again. During the night shift, `RING_MASTER_NIGHT_CONCURRENCY` when set |
| Night budget | `RING_MASTER_NIGHT_BUDGET_USD` (default $50) | No new session in the night window once its runs cost this; see [Night shift](briefing.md#night-shift) |
| Machine load | `RING_MASTER_RUNNER_MAX_LOAD` (default 4.0) | No new session while the one-minute load average per CPU is above it. Empty or 0: no cap |
| Per-session cost | `RING_MASTER_RUNNER_MAX_BUDGET_USD` | Passed as `--max-budget-usd`. Empty: no cap |
| Attempts per task | `RING_MASTER_RUNNER_MAX_ATTEMPTS` (default 2) | Then the task is marked failed |
| Usage-limit back-off | `RING_MASTER_RUNNER_LIMIT_BACKOFF` (default 15 minutes) | See below |

## The session command

```text
claude "<prompt>" -p --output-format stream-json --verbose
  --permission-mode dontAsk --permission-prompts none
  --name "RingMaster run #<id>" [--model <RING_MASTER_RUNNER_MODEL>] [--max-budget-usd <cap>]
  --add-dir <workspace root>
  --mcp-config '{"mcpServers":{"ring-master":{"command":"php","args":["artisan","mcp:start","ring-master"]}}}'
  --allowedTools <runner.allowed_tools…>
```

- It runs in the project's folder, with the workspace root added so it can reach job worktrees beside the package (`../Package--spec`).
- RingMaster's own MCP server is passed in, so sessions need no global setup to reach the queue.
- The environment is the runner's, minus every variable RingMaster's own `.env` sets: a package's tests would otherwise read RingMaster's `DB_CONNECTION`, `APP_ENV` or `SESSION_DRIVER` as their own. `CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS=0` is added so `claude -p` does not stop background work (a factory build) ten minutes after its last turn.
- Sessions run in a process session of their own (`resources/runner/detach.php`), so Ctrl+C in the runner's terminal does not reach them.

## The tool allowlist

Each session may use only `runner.allowed_tools` in `config/ring-master.php` (planner sessions: `planner.allowed_tools`). Anything else is **denied at once** rather than prompting, so a session never hangs waiting for you.

A `Bash(...)` entry must match the command as a whole. A compound command (`cd x && composer test`, pipes, `;`, `$(...)`) is refused whole, so Ringside's skills tell sessions to run one command per call. Default allowlist:

```text
Read Edit Write Glob Grep Workflow Agent Skill mcp__ring-master
Bash(composer *) Bash(vendor/bin/*) Bash(php *) Bash(git *) Bash(gh *) Bash(node *) Bash(npm *) Bash(laravel *)
Bash(ls *) Bash(mkdir *) Bash(echo *) Bash(date) Bash(date *) Bash(pwd) Bash(cd *)
Bash(cat *) Bash(head *) Bash(tail *) Bash(wc *) Bash(sort *) Bash(diff *) Bash(jq *)
```

The Runs page lists the last week's refused calls, grouped by what would fix them:

| Refused | Suggestion |
| --- | --- |
| `Bash: rg foo src` | `Bash(rg *)`: add it to the allowlist if it is safe |
| `Bash: cd x && composer test` | compound command: split it into one call each (fix the skill, not the allowlist) |
| `Bash: /Users/me/pkg/vendor/bin/pest` | absolute path: call it through `composer exec` or a relative path |
| `WebFetch` | the tool name: allow it if sessions should have it |

The allowlist lives in the config file, not `.env`. Edit it there; a running runner restarts into the change on its own.

## Failure classes

When a run fails, the runner reads its log's final result and classes it:

| Class | When | Counts as an attempt |
| --- | --- | --- |
| usage limit | the result's subtype mentions budget, or its text a usage, rate or credit limit, "overloaded" or a quota | no |
| refused commands | the session stopped with calls the allowlist denied | yes |
| environment | the session never started, wrote no result, or the runner went away under it | no |
| work failed | anything else | yes |

After a **usage limit** nothing new starts for `RING_MASTER_RUNNER_LIMIT_BACKOFF` minutes (you are notified once), so every queued task does not burn an attempt on the same limit. Running sessions carry on.

The class shows on the Runs page, on each run, in the activity feed, and per class in the [Workflow page's metrics](workflow.md#last-7-days).

## Stopping and restarting

- **Ctrl+C once** (or SIGTERM): start nothing new and wait for the running sessions to finish. **Twice**: stop them too.
- **Pause** on the Runs page: start nothing new; running sessions finish. The pause survives restarts.
- **Stop** on a run: the runner terminates it on its next tick. The run is marked stopped; a task without a spec goes back to the queue, one with a spec follows the spec.
- **New code**: when anything under `app/`, `config/`, `routes/`, `resources/runner/`, `composer.lock`, the installed packages or Ringside's `src/` changes, the runner re-executes itself on that tick to load it. Running sessions carry on (they are detached and log to files); the new runner adopts them and settles each when it ends. Before it restarts it runs `php -l` on every PHP file changed since it started: if one does not compile (an edit caught halfway), it logs the errors, notifies you once, and carries on with the code it has until the files compile. `.env` is not watched: restart the runner yourself after editing it.
- **A runner that restarts** adopts sessions still running from the previous one (they outlived it in their own process session) and settles them when they end. Runs left "running" with no live process are marked failed (environment) and their tasks given back.

## After a run

- The log's final result gives the run's cost, summary and status (succeeded only when it exited 0 with a non-error result).
- The workspace is rescanned, so a spec the session created is linked and the task follows it.
- A task still held by the run without a spec goes back to the queue, or fails once out of attempts (you are notified). One a session hands back itself (`release-task`) after its attempts are used is paused with the session's reason instead, so a job that each session decides is someone else's does not spin.
- A job cut off mid-stage because the runner went away (failure class environment) gives up its claim and resumes where its spec stands, without waiting for the stall window.
- A failed answers run notifies you: your answers could not be applied.
- A planner run notifies you with how many jobs it suggested.

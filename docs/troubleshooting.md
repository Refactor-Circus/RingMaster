# Troubleshooting

## A task is not starting

Open the task. When it is ready but idle, its page says why:

| Message | Fix |
| --- | --- |
| No runner is running | `php artisan ring-master:work` |
| The runner is paused | **Resume runner** on the Runs page |
| The runner is restarting to load new code | nothing: it restarts within a tick and adopts the running sessions |
| Waiting for run #N in X to finish | one session per folder tree; it starts after that run |
| Waiting for a free slot | raise concurrency on the Runs page |
| Backing off after a usage limit | it starts again by itself after `RING_MASTER_RUNNER_LIMIT_BACKOFF` minutes |
| The machine is busy | the load per CPU is above `RING_MASTER_RUNNER_MAX_LOAD` |
| Tonight's night shift budget is spent | it starts once the night window ends; raise `RING_MASTER_NIGHT_BUDGET_USD` for more |

No message at all means it is not claimable. A job whose spec has a status Ringside does not know shows in the Inbox under **status**. Otherwise check whether it is **paused** (resume it), **blocked** (see *Waits on*; *stop waiting* or resume), **proposed** (accept it in the Inbox), in a **disabled workspace**, or already held by a session.

## A run failed

The run's page shows its failure class and log.

- **refused commands**: the Runs page's *Refused commands* panel says what to do. Add a safe single command to `runner.allowed_tools`; a compound or absolute-path command means a skill told the session to run it that way, so fix the skill in Ringside.
- **usage limit**: nothing to fix; the runner backs off and the attempt does not count. Lower concurrency if it keeps happening.
- **environment**: the session never started or the runner died under it. Check `RING_MASTER_CLAUDE_BIN`, that `claude` works in a terminal, and the run's stderr.
- **work failed**: read the log; the task goes back to the queue until it runs out of attempts.

## A job stays building

A factory build sends no heartbeats. It is resumed once neither its spec folder nor its worktree changed for `RING_MASTER_BUILDING_STALL_MINUTES` (default 60); the factory skips the task groups already done.

## An answer is not being applied

The runner starts an answers run for a project when you answer. If that run failed you were notified, and it is not retried until you answer (or change an answer) again. The answer stays under *Answered, waiting for Claude* until a session applies it.

## A merge is not noticed

PRs are checked every five minutes, only while the runner runs, only for `https://github.com/` URLs in the spec's `meta.yml` `pr:`, and only with `gh` logged in (`gh auth status`).

## Nothing on the board

Check the Workspaces page for a scan error, that the workspace is enabled, and that its projects have `composer.json` (and, for a workspace root, `workspace.packages` in `ring-master.yml`). **Scan now** rescans.

## A chore fails with "gh: not found"

Approving runs `gh` from the web server, which does not have your shell's `PATH`. Add the folder `which gh` prints to `RING_MASTER_PATH`.

## The analyst has not started

The Factory page says why under its header: the same reasons a task waits (runner off, paused, busy machine, no free slot), or a run already working in the workspace, since the analyst reads all of it.

## A spec shows as unreadable

A session broke its `meta.yml` (two keys run together on one line, say). The spec reads as `unreadable` and the Inbox names the file and the YAML error; every other spec scans as usual. Fix the file, then answer **I fixed the file**.

## A notification button does nothing

The link must reach RingMaster: `RING_MASTER_URL` has to be an address your phone can open (Tailscale, home network). Each button works once and expires after `RING_MASTER_PHONE_TTL_HOURS`; open RingMaster to act instead. See [Phone actions](phone.md).

## A replay shows "no result"

Its checkout could not be built (the reason is shown; for example the package has no commit that added the spec, or `composer update` failed), or the factory wrote no `replay.json`. Open the replay run's log from the Runs page.

## The runner's own state

Pause, concurrency override, back-off, planner and analyst requests, the daily briefing guard and timers live in the cache (`CACHE_STORE`, the database by default). `php artisan cache:clear` resets them all: the runner unpauses, concurrency returns to the config value, and the janitor and PR check run on the next tick.

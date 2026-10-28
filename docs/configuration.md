# Configuration

Everything RingMaster-specific lives in `config/ring-master.php`, each section with a banner saying what it controls. Most keys read an `.env` variable; the tool allowlists are set in the file itself.

After changing `.env`, restart the runner (`.env` is not watched). A change to `config/` is picked up by a running runner on its own once its sessions finish.

## Workspaces

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `workspaces` | `RING_MASTER_WORKSPACES` | none | comma-separated folders to scan; `~` expands. Always present; can only be disabled on the Workspaces page. Add more there |

## Scanning

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `scan.stale_after` | `RING_MASTER_SCAN_STALE_AFTER` | `3` | seconds; an open page or the runner rescans when the last scan is older |

## Claims

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `claims.lease_seconds` | `RING_MASTER_LEASE_SECONDS` | `900` | how long a claim lasts without a heartbeat. Expired before a spec exists: back to the queue |
| `claims.building_stall_minutes` | `RING_MASTER_BUILDING_STALL_MINUTES` | `60` | a building job whose spec folder and worktree have not changed for this long counts as stalled and is resumed |

## CI

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `ci.auto_fix_attempts` | `RING_MASTER_CI_AUTO_FIX` | `2` | times per job failing PR checks send it back on their own; `0`: always ask in the Inbox. See [CI auto-fix](pull-requests.md#ci-auto-fix) |

## Janitor

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `janitor.enabled` | `RING_MASTER_JANITOR` | `true` | run the janitor from the runner |
| `janitor.every_minutes` | `RING_MASTER_JANITOR_EVERY` | `60` | minutes between passes |
| `janitor.dependabot` | `RING_MASTER_DEPENDABOT` | `true` | triage Dependabot PRs on each pass |
| `janitor.skip` | `RING_MASTER_JANITOR_SKIP` | none | comma-separated project folders to leave alone |

See [Janitor and Dependabot](janitor.md).

## Planner

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `planner.every_days` | `RING_MASTER_PLANNER_EVERY_DAYS` | none | plan each workspace on a schedule; empty: only on **Plan now** |
| `planner.max_suggestions` | `RING_MASTER_PLANNER_MAX` | `5` | jobs per plan run |
| `planner.allowed_tools` | (file) | `Read`, `Glob`, `Grep`, `mcp__ring-master` | all a planner session may use |

See [Portfolio planner](planner.md).

## Night shift and briefing

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `night_shift.enabled` | `RING_MASTER_NIGHT_SHIFT` | `true` | the night window, its budget and concurrency |
| `night_shift.start` / `end` | `RING_MASTER_NIGHT_START` / `_END` | `22:00` / `07:00` | local time, `HH:MM`; may cross midnight |
| `night_shift.budget_usd` | `RING_MASTER_NIGHT_BUDGET_USD` | `50` | no new session in the window once its runs cost this; empty: no cap |
| `night_shift.concurrency` | `RING_MASTER_NIGHT_CONCURRENCY` | none | sessions at once in the window; empty: the usual |
| `night_shift.briefing_at` | `RING_MASTER_BRIEFING_AT` | `07:30` | when the runner makes the morning briefing; empty: only on request |

See [Night shift and briefing](briefing.md).

## Decision precedent

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `precedent.min_similarity` | `RING_MASTER_PRECEDENT_SIMILARITY` | `0.5` | how alike (0 to 1) a past question must be to be offered. See [Decision precedent](precedent.md) |

## Signals

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `signals.enabled` | `RING_MASTER_SIGNALS` | `true` | read issues, flaky checks and stale Dependabot PRs on each janitor pass. See [Signals](planner.md#signals) |

## Factory improvements

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `improve.every_days` | `RING_MASTER_IMPROVE_EVERY_DAYS` | none | run the factory analyst on a schedule; empty: only on **Analyse the factory** |
| `improve.max_proposals` | `RING_MASTER_IMPROVE_MAX` | `3` | proposals per analyst run |
| `improve.replay_specs` | `RING_MASTER_REPLAY_SPECS` | `3` | recent jobs replayed per evaluation |
| `improve.budget_usd` | `RING_MASTER_REPLAY_BUDGET_USD` | `20` | for a whole evaluation, split evenly across its replays |

See [The factory improving itself](factory.md).

## Phone actions

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `phone.actions` | `RING_MASTER_PHONE_ACTIONS` | `true` | buttons on notifications |
| `phone.ttl_hours` | `RING_MASTER_PHONE_TTL_HOURS` | `24` | how long a button's link works (once) |

See [Phone actions](phone.md).

## Notifications

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `notifications.channels` | `RING_MASTER_NOTIFY` | none | comma-separated: `desktop`, `mail`, `webhook`, `ntfy` |
| `notifications.mail_to` | `RING_MASTER_NOTIFY_MAIL` | none | the `mail` channel's address (sent with Laravel's `MAIL_*` settings) |
| `notifications.webhook_url` | `RING_MASTER_NOTIFY_WEBHOOK` | none | the `webhook` channel's URL |
| `notifications.ntfy_url` | `RING_MASTER_NOTIFY_NTFY` | none | the `ntfy` channel's topic URL, e.g. `https://ntfy.sh/<secret topic>` |
| `notifications.ntfy_token` | `RING_MASTER_NOTIFY_NTFY_TOKEN` | none | an ntfy access token, for a protected topic |
| `notifications.app_url` | `RING_MASTER_URL`, else `APP_URL` | `http://127.0.0.1:8000` | where links and notification buttons point; for phone buttons, an address only your devices reach |

See [Notifications](notifications.md).

## Runner

| Key | `.env` | Default | |
| --- | --- | --- | --- |
| `runner.claude_bin` | `RING_MASTER_CLAUDE_BIN` | `claude` | the Claude Code executable (a name on `PATH` or a path) |
| `runner.path` | `RING_MASTER_PATH` | `/opt/homebrew/bin:/usr/local/bin` | folders added to `PATH`, when they exist and are missing, for every command RingMaster runs; a web server often starts without your shell's `PATH`, so `gh` would not be found when you approve a chore |
| `runner.concurrency` | `RING_MASTER_RUNNER_CONCURRENCY` | `3` | sessions at once; the Runs page overrides it live |
| `runner.model` | `RING_MASTER_RUNNER_MODEL` | none | `--model` for every session; empty: Claude Code's default. Ringside's skills and factory roles still pick their own models |
| `runner.max_budget_usd` | `RING_MASTER_RUNNER_MAX_BUDGET_USD` | none | `--max-budget-usd` per session; empty: no cap |
| `runner.max_attempts` | `RING_MASTER_RUNNER_MAX_ATTEMPTS` | `2` | task runs before the task is marked failed |
| `runner.tick_seconds` | `RING_MASTER_RUNNER_TICK_SECONDS` | `5` | seconds between ticks |
| `runner.max_load` | `RING_MASTER_RUNNER_MAX_LOAD` | `4.0` | no new session while the one-minute load average per CPU is above it; empty or `0`: no cap. Sessions' own test runs (`pest --parallel`, phpstan) push it up, so keep it well above 1 |
| `runner.limit_backoff_minutes` | `RING_MASTER_RUNNER_LIMIT_BACKOFF` | `15` | start nothing new for this long after a run hits a usage limit |
| `runner.allowed_tools` | (file) | see [Runner](runner.md#the-tool-allowlist) | all a task or answers session may use; anything else is denied at once |

## Laravel settings that matter

| `.env` | Default | |
| --- | --- | --- |
| `DB_CONNECTION` | `sqlite` | `database/database.sqlite`. The runner, scans and pages share it |
| `CACHE_STORE` | `database` | holds the runner's live state: pause, heartbeat, concurrency override, back-off, planner and analyst requests, timers. Must be shared between the web server and the runner, so not `array` |
| `MAIL_*` | `log` | for the `mail` notification channel |
| `APP_URL` | | fallback for `RING_MASTER_URL` |
| `APP_KEY` | | signs notification button links; changing it voids the ones sent |

None of RingMaster's own `.env` variables reach the sessions it starts (see [Runner](runner.md#the-session-command)).

## Per-project settings

How a job is built (stages, roles and models, the factory, reviewers, parallel groups, the impact gate) is set in each project's `ring-master.yml` and belongs to Ringside; see Ringside's README. The [Workflow page](workflow.md) shows the result. The one key RingMaster relies on there:

```yaml
board: { app: ~/Herd/RingMaster }   # turns on /ring-master-inbox and the session summary
```

# Development

PHP 8.5, Laravel 13, Livewire 4 (class components in `app/Livewire`), Flux, SQLite, Pest. Ringside is a Composer dependency: RingMaster reads spec folders and `ring-master.yml` through its classes (`Specs\Board`, `Specs\Job`, `Config`).

```bash
composer test     # pint --test, phpstan, pest
```

## Layout

| Folder | Holds |
| --- | --- |
| `app/Actions` | everything that changes state: `ScanWorkspaces`, `CreateTask`, `UpdateTask`, `ChangeTaskStatus`, `ClaimTasks`, `TaskDependencies`, `TaskProposals`, `AnswerAttentionItem`, `SendBackSpec`, `Chores`, `Campaigns`, `Improvements` |
| `app/Runner` | `Runner` (the loop), `ClaudeCommand` (the `claude -p` command line), `RunLog` (reads stream-json logs) |
| `app/Support` | `ProjectDiscovery`, `JobActivity` (worktree activity), `PullRequests` (gh checks and merges), `Janitor`, `Dependabot`, `Planner`, `Signals`, `NightShift`, `Briefings`, `Risk`, `Reviews`, `Precedents`, `DependencyOrder`, `Improver` (the analyst), `Replays`, `ActionLinks`, `WorkflowMap`, `Notifier`, `NavCounts` |
| `app/Mcp` | the MCP server and its tools |
| `app/Livewire` | one component per page, plus the command bar, activity feed, answer form, nav counter and browser notifier |
| `app/Enums` | task, run, attention and chore statuses and kinds, `RunFailure` |
| `app/Models` | `Workspace`, `Project`, `Spec`, `Task`, `AttentionItem`, `Run`, `Chore`, `Activity`, `Briefing`, `Campaign`, `Decision`, `Improvement`, `Signal`, `ActionToken` |
| `app/Http/Controllers/ActionController.php` | the signed single-use links behind notification buttons |
| `resources/runner/detach.php` | runs a session in its own process session |
| `routes/console.php` | the `ring-master:*` commands and the schedule |

## Data

| Table | |
| --- | --- |
| `workspaces` | scanned folders (`from_config`, `enabled`, `error`) |
| `projects` | per workspace (`folder`, `path`, `is_workspace`) |
| `specs` | mirrored spec folders, with `pr_merged_at`, `activity_at` and `ci_fixes` |
| `tasks` | the queue, with claim, lease, attempts, pause, proposal and resume fields |
| `task_dependencies` | the job graph: `task_id` waits on `depends_on_task_id` |
| `attention_items` | questions and escalations, keyed per spec (`q:`, `escalation:`, `rework:`, `checks:`) |
| `runs` | sessions, with log path, pid, cost, `failure` and `refused` |
| `chores` | Inbox housekeeping, keyed per project |
| `activities` | the feed and timelines |
| `briefings` | morning briefing snapshots (`since`, `data`) |
| `campaigns` | campaigns; `tasks.campaign_id` links their tasks |
| `decisions` | precedents: question, options, answer, note, normalised `terms` |
| `improvements` | factory improvements, their build task, branch and replay `results`; `runs.improvement_id` and `runs.replay_of` link analyst and replay runs |
| `signals` | GitHub signals per project, keyed |
| `action_tokens` | notification button tokens: action, subject, payload, expiry, use |

Adding a plain nullable column is safe on SQLite (`ALTER TABLE ADD COLUMN`); adding a constrained foreign key to an existing table is not, since Laravel rebuilds the table for it. That is why `tasks.campaign_id`, `runs.improvement_id` and `runs.replay_of` have no constraint.

SQLite cannot drop a column in place: Laravel rebuilds the table, and inside a transaction `PRAGMA foreign_keys=off` is ignored, so the rebuild would cascade-delete child rows. A migration that drops or changes a column on a table others reference must set `public $withinTransaction = false;` (see `2026_10_27_000008_create_task_dependencies_table.php`). Try it on a copy of `database/database.sqlite` first.

## Ringside

CI installs Ringside `dev-main` from GitHub (`composer.json`). Locally, `composer local:install` symlinks your checkout through the gitignored `composer.local.json` and `composer.local.lock` (`bin/composer-local.php`). A RingMaster change that needs an unmerged Ringside change passes locally but not in CI until that Ringside PR is merged and `composer update refactor-circus/ringside` moves the lock.

## Tests

- `tests/fixtures/fake-claude` stands in for `claude` in runner tests. `FAKE_CLAUDE_MODE` (`ok`, `fail`, `limit`, `refused`, `sleep`, `spec`) decides the stream-json it writes.
- `fixtureWorkspace()` builds a throwaway workspace of Ringside projects.
- Janitor tests use real git repos with a bare origin; GitHub calls are faked with `Process::fake`.
- `phpunit.xml` turns the night shift, the daily briefing and signals off (`RING_MASTER_NIGHT_SHIFT=false`, `RING_MASTER_BRIEFING_AT=(empty)`, `RING_MASTER_SIGNALS=false`), so runner tests do not depend on the clock or GitHub; their own tests turn them on.

## Rules of the house

- RingMaster never edits project files itself. The only thing it changes in a checkout is the janitor removing merged, clean worktrees and branches.
- Nothing merges a job's PR, and nothing changes GitHub without your OK in the Inbox.
- Anything a session would ask a human goes to the Inbox; sessions never prompt.

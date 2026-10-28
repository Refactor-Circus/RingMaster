<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One spec folder (one job), mirrored from disk by the scanner.
 *
 * @property int $id
 * @property int $project_id
 * @property string $key
 * @property string $dir relative to any checkout of the package
 * @property string|null $path the spec folder, absolute: in its job worktree, or on main once merged
 * @property string|null $worktree the job worktree that holds the spec, or null once it is only on main
 * @property string $title
 * @property string $status
 * @property string $column
 * @property array<string, mixed>|null $ticket
 * @property array<array-key, mixed>|null $pr one PR, or a list (or map) of per-package PRs
 * @property string|null $branch
 * @property int $tasks_done
 * @property int $tasks_total
 * @property list<string> $packages
 * @property list<array{n: int, reports: list<array{name: string, verdict: ?string, path: string}>}> $rounds
 * @property int $decisions
 * @property string|null $decision
 * @property int $questions
 * @property list<string> $files
 * @property CarbonImmutable|null $spec_updated_at
 * @property CarbonImmutable|null $pr_merged_at when the runner saw every PR merged
 * @property int $ci_fixes times failing PR checks sent it back on their own
 * @property CarbonImmutable|null $activity_at last write to the spec folder or, while building, its worktree
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'key', 'dir', 'path', 'worktree', 'title', 'status', 'column', 'ticket', 'pr', 'branch', 'tasks_done', 'tasks_total', 'packages', 'rounds', 'decisions', 'decision', 'questions', 'files', 'spec_updated_at', 'activity_at', 'pr_merged_at', 'ci_fixes'])]
class Spec extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<AttentionItem, $this>
     */
    public function attentionItems(): HasMany
    {
        return $this->hasMany(AttentionItem::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * The spec folder, absolute, wherever it lives; a row scanned before
     * specs moved into worktrees knows only its place on main.
     */
    public function folder(): string
    {
        return $this->path ?? $this->project->path.'/'.$this->dir;
    }

    /**
     * A file in the spec folder, or null when it is missing.
     */
    public function read(string $name): ?string
    {
        $file = $this->folder().'/'.$name;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * The latest round's reports, gate first.
     *
     * @return list<array{name: string, verdict: ?string, path: string}>
     */
    public function latestReports(): array
    {
        $rounds = $this->rounds;

        return $rounds === [] ? [] : $rounds[array_key_last($rounds)]['reports'];
    }

    /**
     * The interface tone for the spec's board column.
     */
    /** Tasks in tasks.md not checked off yet. */
    public function unbuiltTasks(): int
    {
        return max(0, $this->tasks_total - $this->tasks_done);
    }

    public function tone(): string
    {
        return match ($this->column) {
            'Building' => 'live',
            'Needs you' => 'wait',
            'In review' => 'review',
            'Done' => 'done',
            default => 'idle',
        };
    }

    public function ticketKey(): ?string
    {
        $key = $this->ticket['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The ticket's link, only when it is an https URL.
     */
    public function ticketUrl(): ?string
    {
        return self::https($this->ticket['url'] ?? null);
    }

    /**
     * Pull request links: one PR, or one per package in a workspace job.
     *
     * @return list<array{label: string, url: string}>
     */
    public function pullRequests(): array
    {
        $pr = $this->pr ?? [];

        if (($url = self::https($pr['url'] ?? null)) !== null) {
            return [['label' => 'PR', 'url' => $url]];
        }

        $links = [];

        foreach ($pr as $package => $entry) {
            if (is_array($entry) && ($url = self::https($entry['url'] ?? null)) !== null) {
                $label = is_string($entry['package'] ?? null) ? $entry['package'] : (is_string($package) ? $package : 'PR '.($package + 1));
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        return $links;
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket' => 'array',
            'pr' => 'array',
            'packages' => 'array',
            'rounds' => 'array',
            'files' => 'array',
            'spec_updated_at' => 'datetime',
            'activity_at' => 'datetime',
            'pr_merged_at' => 'datetime',
        ];
    }
}

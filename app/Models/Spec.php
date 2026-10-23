<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One spec folder (one job), mirrored from disk by the scanner.
 *
 * @property int $id
 * @property int $project_id
 * @property string $key
 * @property string $dir
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
 * @property Carbon|null $spec_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'key', 'dir', 'title', 'status', 'column', 'ticket', 'pr', 'branch', 'tasks_done', 'tasks_total', 'packages', 'rounds', 'decisions', 'decision', 'questions', 'files', 'spec_updated_at'])]
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
     * The latest round's reports, gate first.
     *
     * @return list<array{name: string, verdict: ?string, path: string}>
     */
    public function latestReports(): array
    {
        $rounds = $this->rounds;

        return $rounds === [] ? [] : $rounds[array_key_last($rounds)]['reports'];
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
        ];
    }
}

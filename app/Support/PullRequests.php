<?php

namespace App\Support;

use App\Actions\SendBackSpec;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Models\Activity;
use App\Models\Spec;
use Illuminate\Support\Facades\Process;

/**
 * Watches the PRs of jobs in review through gh. Spec folders only learn of
 * a merge when a session checks, so a merge is recorded here and the task
 * goes back to a session, which marks the spec merged and runs the
 * post-mortem. Failing checks send the job back to the factory on their
 * own, up to ci.auto_fix_attempts times; after that they become an Inbox
 * item: send the job back with them, or leave it.
 */
final class PullRequests
{
    /** Key prefix of the Inbox items for failing checks. */
    public const string CHECKS = 'checks:';

    public function __construct(private readonly Notifier $notifier, private readonly SendBackSpec $sendBack, private readonly Janitor $janitor) {}

    /**
     * @return int specs found merged
     */
    public function refresh(): int
    {
        $merged = 0;

        foreach (Spec::query()->where('status', 'in-review')->whereNull('pr_merged_at')->get() as $spec) {
            if ($this->merged($spec)) {
                $spec->update(['pr_merged_at' => now()]);
                Activity::record('spec.merged', 'PR merged: a session records it and runs the post-mortem', spec: $spec);
                // Packages that use this one build against its local checkout: bring it up to the merge now.
                $this->janitor->update($spec->project);
                $merged++;

                continue;
            }

            $this->checks($spec);
        }

        return $merged;
    }

    /**
     * Raises one Inbox item per set of failing checks; a new set replaces an
     * unanswered older one, and green checks close it.
     */
    private function checks(Spec $spec): void
    {
        $failing = [];

        foreach ($this->githubUrls($spec) as $url) {
            // gh exits non-zero while checks fail or are pending; the JSON is still printed.
            $result = Process::timeout(30)->run(['gh', 'pr', 'checks', $url, '--json', 'name,bucket,link,workflow']);
            $checks = json_decode(trim($result->output()), true);

            if (! is_array($checks)) {
                return;
            }

            foreach ($checks as $check) {
                if (is_array($check) && ($check['bucket'] ?? null) === 'fail') {
                    $failing[] = ['pr' => $url, 'name' => (string) ($check['name'] ?? ''), 'workflow' => (string) ($check['workflow'] ?? ''), 'link' => (string) ($check['link'] ?? '')];
                }
            }
        }

        $key = $failing === [] ? null : self::CHECKS.substr(sha1((string) json_encode(array_map(fn (array $c): string => $c['pr'].$c['name'].$c['link'], $failing))), 0, 16);
        $stale = $spec->attentionItems()->where('key', 'like', self::CHECKS.'%')->where('status', AttentionStatus::Open)->when($key !== null, fn ($query) => $query->where('key', '!=', $key))->get();

        foreach ($stale as $item) {
            $item->update(['status' => AttentionStatus::Closed, 'closed_at' => now()]);
            Activity::record('attention.closed', $key === null ? 'PR checks pass again' : 'PR checks changed', spec: $spec);
        }

        if ($key === null || $spec->attentionItems()->where('key', $key)->exists()) {
            return;
        }

        $lines = array_map(fn (array $c): string => "- {$c['workflow']} / {$c['name']}: {$c['link']}", $failing);
        $note = "The PR's CI checks fail. Read the failing job logs (gh run view <run> --log-failed) and fix the cause:\n".implode("\n", $lines);

        if ($spec->ci_fixes < (int) config('ring-master.ci.auto_fix_attempts', 2)) {
            $this->autoFix($spec, $key, $failing, $lines, $note);

            return;
        }

        $item = $spec->attentionItems()->create([
            'key' => $key,
            'kind' => AttentionKind::Escalation,
            'question' => count($failing).' PR '.(count($failing) === 1 ? 'check fails' : 'checks fail').'. Send the job back to fix them?',
            'options' => [AttentionKind::REWORK, AttentionKind::LEAVE],
            'evidence' => implode("\n", $lines),
            // Your note when you send it back; edit it before saving.
            'note' => $note,
            'source' => 'ci',
            'status' => AttentionStatus::Open,
        ]);

        Activity::record('attention.raised', 'Escalated: '.$item->question, spec: $spec);
        $this->notifier->send('PR checks fail', ["{$spec->project->folder}: {$spec->title}: ".count($failing).' failing'], "/specs/{$spec->id}");
    }

    /**
     * Sends the job back with the failing checks as its note. The set is
     * kept as a closed item under its key, so the same failures are not sent
     * back again while the rework is waiting to start.
     *
     * @param  list<array{pr: string, name: string, workflow: string, link: string}>  $failing
     * @param  list<string>  $lines
     */
    private function autoFix(Spec $spec, string $key, array $failing, array $lines, string $note): void
    {
        $attempt = $spec->ci_fixes + 1;
        $spec->attentionItems()->create([
            'key' => $key,
            'kind' => AttentionKind::Escalation,
            'question' => count($failing).' PR '.(count($failing) === 1 ? 'check failed' : 'checks failed').": sent back on its own (attempt {$attempt})",
            'options' => [AttentionKind::REWORK],
            'evidence' => implode("\n", $lines),
            'answer' => AttentionKind::REWORK,
            'source' => 'ci',
            'status' => AttentionStatus::Closed,
            'answered_at' => now(),
            'closed_at' => now(),
        ]);
        $spec->update(['ci_fixes' => $attempt]);
        $this->sendBack->handle($spec, $note);
        Activity::record('ci.autofix', "PR checks fail: sent back to fix them (attempt {$attempt} of ".(int) config('ring-master.ci.auto_fix_attempts', 2).')', spec: $spec);
    }

    /**
     * @return list<string>
     */
    private function githubUrls(Spec $spec): array
    {
        return array_values(array_filter(array_column($spec->pullRequests(), 'url'), fn (string $url): bool => str_starts_with($url, 'https://github.com/')));
    }

    /**
     * True when every one of the spec's PRs is on GitHub and merged.
     */
    private function merged(Spec $spec): bool
    {
        $urls = array_column($spec->pullRequests(), 'url');

        if ($urls === [] || count($this->githubUrls($spec)) !== count($urls)) {
            return false;
        }

        foreach ($urls as $url) {
            $result = Process::timeout(30)->run(['gh', 'pr', 'view', $url, '--json', 'state', '--jq', '.state']);

            if (! $result->successful() || trim($result->output()) !== 'MERGED') {
                return false;
            }
        }

        return true;
    }
}

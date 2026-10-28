<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Spec;
use Illuminate\Support\Facades\Process;

/**
 * Your review of a job's PRs from the briefing. It posts an approving review
 * on each; GitHub refuses that on a PR you opened yourself (the factory opens
 * them as you), so then it labels the PR ring-master:reviewed and comments
 * instead. It never merges: that stays your click on GitHub.
 */
final class Reviews
{
    public const string LABEL = 'ring-master:reviewed';

    /**
     * @return list<string> one line per PR saying what was done
     */
    public function approve(Spec $spec): array
    {
        $done = [];

        foreach ($spec->pullRequests() as $pr) {
            if (! str_starts_with($pr['url'], 'https://github.com/')) {
                continue;
            }

            $body = 'Reviewed and approved from the RingMaster briefing.';
            $approved = Process::path($spec->project->path)->timeout(60)->run(['gh', 'pr', 'review', $pr['url'], '--approve', '--body', $body]);

            if ($approved->successful()) {
                $done[] = "{$pr['url']}: approved";

                continue;
            }

            // Your own PR: GitHub allows no approval, so mark it reviewed instead.
            Process::path($spec->project->path)->timeout(30)->run(['gh', 'label', 'create', self::LABEL, '--color', '0E8A16', '--description', 'Reviewed in RingMaster', '--force']);
            $labelled = Process::path($spec->project->path)->timeout(60)->run(['gh', 'pr', 'edit', $pr['url'], '--add-label', self::LABEL]);
            Process::path($spec->project->path)->timeout(60)->run(['gh', 'pr', 'comment', $pr['url'], '--body', $body.' (GitHub does not allow approving your own PR, so it is labelled '.self::LABEL.'.)']);

            $done[] = $labelled->successful() ? "{$pr['url']}: labelled ".self::LABEL : "{$pr['url']}: could not approve or label (".trim($approved->errorOutput()).')';
        }

        Activity::record('review.approved', 'Reviewed from the briefing: '.implode('; ', $done), spec: $spec);

        return $done;
    }
}

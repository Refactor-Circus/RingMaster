<?php

namespace App\Support;

use App\Enums\AttentionKind;
use App\Models\Spec;
use Illuminate\Support\Facades\Process;

/**
 * How carefully a job in review needs reading, from what is known about it:
 * the size of its diff, whether it touches architectural paths or no tests,
 * how many review rounds and CI fixes it took, whether it escalated, and the
 * briefer's own call in brief.md. A score from 0 to 100 with its reasons,
 * so the briefing can put the quick reviews first.
 *
 * @phpstan-type Assessment array{score: int, level: string, reasons: list<string>, additions: int, deletions: int, files: int}
 */
final class Risk
{
    /**
     * @return Assessment
     */
    public function assess(Spec $spec): array
    {
        $score = 0;
        $reasons = [];
        [$additions, $deletions, $paths] = $this->diff($spec);
        $lines = $additions + $deletions;

        if ($lines > 800) {
            [$score, $reasons[]] = [$score + 30, "large diff ({$lines} lines)"];
        } elseif ($lines > 300) {
            [$score, $reasons[]] = [$score + 15, "medium diff ({$lines} lines)"];
        }

        $architectural = array_values(array_filter($paths, fn (string $path): bool => $this->architectural($spec, $path)));

        if ($architectural !== []) {
            [$score, $reasons[]] = [$score + 25, 'touches '.implode(', ', array_slice($architectural, 0, 3)).(count($architectural) > 3 ? ' and more' : '')];
        }

        if ($paths !== [] && ! array_filter($paths, fn (string $path): bool => (bool) preg_match('#(^|/)tests?/#', $path))) {
            [$score, $reasons[]] = [$score + 20, 'no test changes'];
        }

        if (($rounds = count($spec->rounds)) > 2) {
            [$score, $reasons[]] = [$score + 10 * min(3, $rounds - 2), "{$rounds} review rounds"];
        }

        if ($spec->attentionItems()->where('kind', AttentionKind::Escalation)->where('source', 'build')->exists()) {
            [$score, $reasons[]] = [$score + 15, 'escalated before'];
        }

        if ($spec->ci_fixes > 0) {
            [$score, $reasons[]] = [$score + 10 * $spec->ci_fixes, "CI fixed {$spec->ci_fixes}×"];
        }

        if (count($spec->packages) > 1) {
            [$score, $reasons[]] = [$score + 10, count($spec->packages).' packages'];
        }

        $briefed = $this->briefed($spec);

        if ($briefed === 'high') {
            [$score, $reasons[]] = [$score + 25, 'the briefer rates it high'];
        } elseif ($briefed === 'medium') {
            [$score, $reasons[]] = [$score + 10, 'the briefer rates it medium'];
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'level' => $score < 25 ? 'low' : ($score < 50 ? 'medium' : 'high'),
            'reasons' => $reasons === [] ? ['small, tested, reviewed once'] : $reasons,
            'additions' => $additions,
            'deletions' => $deletions,
            'files' => count($paths),
        ];
    }

    /**
     * The reviewer brief the factory wrote (brief.md), or null.
     */
    public function brief(Spec $spec): ?string
    {
        if (! in_array($spec->dir.'/brief.md', $spec->files, true)) {
            return null;
        }

        $brief = $spec->read('brief.md');

        return is_string($brief) && trim($brief) !== '' ? trim($brief) : null;
    }

    private function briefed(Spec $spec): ?string
    {
        return preg_match('/^\s*Risk:\s*(low|medium|high)\b/im', (string) $this->brief($spec), $m) ? strtolower($m[1]) : null;
    }

    /**
     * Lines added and removed and the paths changed, across the spec's GitHub PRs.
     *
     * @return array{int, int, list<string>}
     */
    private function diff(Spec $spec): array
    {
        $additions = $deletions = 0;
        $paths = [];

        foreach ($spec->pullRequests() as $pr) {
            if (! str_starts_with($pr['url'], 'https://github.com/')) {
                continue;
            }

            $view = json_decode(trim(Process::timeout(30)->run(['gh', 'pr', 'view', $pr['url'], '--json', 'additions,deletions,files'])->output()), true);

            if (! is_array($view)) {
                continue;
            }

            $additions += (int) ($view['additions'] ?? 0);
            $deletions += (int) ($view['deletions'] ?? 0);

            foreach (is_array($view['files'] ?? null) ? $view['files'] : [] as $file) {
                // The PR carries the job's spec record; only the code it changes counts toward its risk.
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }

                if (str_starts_with($file['path'], dirname($spec->dir).'/')) {
                    $additions -= (int) ($file['additions'] ?? 0);
                    $deletions -= (int) ($file['deletions'] ?? 0);

                    continue;
                }

                $paths[] = $file['path'];
            }
        }

        return [$additions, $deletions, $paths];
    }

    private function architectural(Spec $spec, string $path): bool
    {
        foreach ($spec->project->ringside()->config->list('escalation.architectural_paths') as $glob) {
            $regex = '#^'.strtr(preg_quote((string) $glob, '#'), ['\*\*/' => '(.*/)?', '\*\*' => '.*', '\*' => '[^/]*', '\?' => '[^/]']).'$#';

            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}

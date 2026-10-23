<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RefactorCircus\Ringside\Project;
use RefactorCircus\Ringside\Specs\Job;
use SplFileInfo;
use Throwable;

/**
 * When a building job last touched its worktree: the newest file outside
 * vendor/, node_modules/ and .git/, and the worktree's git metadata (so a
 * commit counts). The factory writes as it works, so a long quiet stretch
 * means it stopped.
 */
final class JobActivity
{
    private const int MAX_FILES = 20000;

    private const array SKIP = ['vendor', 'node_modules', '.git'];

    public function latest(Project $project, string $spec): ?int
    {
        try {
            $job = (new Job)->build($project, $spec);
        } catch (Throwable) {
            return null;
        }

        $dir = is_array($job['workspace'] ?? null) ? $job['workspace']['dir'] : $job['job']['worktree'];
        $path = str_starts_with($dir, '/') ? $dir : $project->root.'/'.$dir;

        return is_dir($path) ? $this->newest($path) : null;
    }

    private function newest(string $root): ?int
    {
        $latest = null;
        $seen = 0;

        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), self::SKIP, true),
        ));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $latest = max($latest ?? 0, $file->getMTime());
            }

            if (++$seen >= self::MAX_FILES) {
                break;
            }
        }

        foreach (glob($root.'{,/*}/.git', GLOB_BRACE) ?: [] as $marker) {
            $latest = max($latest ?? 0, $this->gitActivity($marker) ?? 0);
        }

        return $latest;
    }

    /**
     * A worktree's .git is a file naming its git dir, where commits and
     * staging update index and logs/HEAD.
     */
    private function gitActivity(string $marker): ?int
    {
        $gitDir = is_file($marker) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($marker), $m) ? trim($m[1]) : $marker;
        $gitDir = str_starts_with($gitDir, '/') ? $gitDir : dirname($marker).'/'.$gitDir;
        $times = [];

        foreach (['index', 'logs/HEAD', 'HEAD'] as $file) {
            if (is_file($gitDir.'/'.$file)) {
                $times[] = (int) filemtime($gitDir.'/'.$file);
            }
        }

        return $times === [] ? null : max($times);
    }
}

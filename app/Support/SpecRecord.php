<?php

namespace App\Support;

use App\Models\Spec;
use Illuminate\Support\Facades\Process;

/**
 * A spec's folder is committed on its job branch, in its job worktree, so the
 * PR carries the record. What RingMaster writes there itself is committed the
 * same way; a spec only on the main checkout is never committed there.
 */
final class SpecRecord
{
    /**
     * Commits the spec folder alone as "<key>: <message>"; false when there
     * is no job worktree or nothing changed.
     */
    public static function commit(Spec $spec, string $message): bool
    {
        if ($spec->worktree === null || ! is_dir($spec->worktree)) {
            return false;
        }

        $git = Process::path($spec->worktree)->timeout(30);
        $git->run(['git', 'add', '--all', '--', $spec->dir]);

        if ($git->run(['git', 'diff', '--cached', '--quiet', '--', $spec->dir])->successful()) {
            return false;
        }

        return $git->run(['git', 'commit', '--quiet', '-m', "{$spec->key}: {$message}", '--', $spec->dir])->successful();
    }
}

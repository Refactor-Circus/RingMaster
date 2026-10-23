<?php

namespace App\Support;

use RefactorCircus\Ringside\Config;
use RefactorCircus\Ringside\Project;

/**
 * Finds the projects inside a workspace folder:
 *
 * - a folder whose ring-master.yml lists workspace.packages: itself (for
 *   workspace-level specs) plus each listed package that exists;
 * - a folder with its own composer.json: just itself;
 * - any other folder: each direct subfolder that has a composer.json.
 */
final class ProjectDiscovery
{
    /**
     * @return list<array{path: string, folder: string, is_workspace: bool}>
     */
    public function discover(string $root): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $packages = is_file($root.'/'.Config::FILE) ? (new Project($root))->config->list('workspace.packages') : [];

        if ($packages !== []) {
            $found = [self::entry($root, true)];

            foreach ($packages as $package) {
                if (is_file($root.'/'.$package.'/composer.json')) {
                    $found[] = self::entry($root.'/'.$package, false);
                }
            }

            return $found;
        }

        if (is_file($root.'/composer.json')) {
            return [self::entry($root, false)];
        }

        $found = [];

        foreach (glob($root.'/*/composer.json') ?: [] as $manifest) {
            $found[] = self::entry(dirname($manifest), false);
        }

        return $found;
    }

    /**
     * Expands a leading "~" to the home folder. Web servers often run without
     * HOME set, so it falls back to the account's home from the passwd entry,
     * and leaves the path as it is when neither is known.
     */
    public static function expand(string $path): string
    {
        if ($path !== '~' && ! str_starts_with($path, '~/')) {
            return $path;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (! is_string($home) || $home === '') {
            $entry = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
            $home = is_array($entry) ? $entry['dir'] : '';
        }

        return $home === '' ? $path : rtrim($home, '/').substr($path, 1);
    }

    /**
     * @return array{path: string, folder: string, is_workspace: bool}
     */
    private static function entry(string $path, bool $isWorkspace): array
    {
        $path = realpath($path) ?: $path;

        return ['path' => $path, 'folder' => basename($path), 'is_workspace' => $isWorkspace];
    }
}

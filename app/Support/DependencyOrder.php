<?php

namespace App\Support;

use App\Models\Project;

/**
 * The order changes must land in across packages: a package goes after every
 * package it requires (require or require-dev, by composer name). Returns
 * waves, each a list of packages that can be worked on side by side. A
 * dependency cycle, which Composer allows, lands its packages together in a
 * last wave.
 */
final class DependencyOrder
{
    /**
     * @param  iterable<Project>  $projects
     * @return array{waves: list<list<Project>>, requires: array<int, list<int>>, cycle: list<Project>}
     */
    public function waves(iterable $projects): array
    {
        $byName = [];
        $all = [];

        foreach ($projects as $project) {
            $all[$project->id] = $project;
            $name = self::composerName($project);

            if ($name !== null) {
                $byName[$name] = $project->id;
            }
        }

        $requires = [];

        foreach ($all as $id => $project) {
            $requires[$id] = array_values(array_unique(array_filter(
                array_map(fn (string $name): ?int => $byName[$name] ?? null, self::requires($project)),
                fn (?int $other): bool => $other !== null && $other !== $id,
            )));
        }

        $waves = [];
        $placed = [];

        while (count($placed) < count($all)) {
            $wave = array_values(array_filter(array_keys($all), fn (int $id): bool => ! isset($placed[$id]) && array_diff($requires[$id], array_keys($placed)) === []));

            if ($wave === []) {
                break;
            }

            sort($wave);
            $waves[] = array_map(fn (int $id): Project => $all[$id], $wave);

            foreach ($wave as $id) {
                $placed[$id] = true;
            }
        }

        $cycle = array_values(array_map(fn (int $id): Project => $all[$id], array_diff(array_keys($all), array_keys($placed))));

        if ($cycle !== []) {
            $waves[] = $cycle;
        }

        return ['waves' => $waves, 'requires' => $requires, 'cycle' => $cycle];
    }

    public static function composerName(Project $project): ?string
    {
        $manifest = self::manifest($project);

        return is_string($manifest['name'] ?? null) ? $manifest['name'] : null;
    }

    /**
     * @return list<string>
     */
    private static function requires(Project $project): array
    {
        $manifest = self::manifest($project);

        return array_map('strval', array_keys([...(is_array($manifest['require'] ?? null) ? $manifest['require'] : []), ...(is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [])]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(Project $project): array
    {
        $file = $project->path.'/composer.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }
}

<?php

/**
 * Regenerates composer.local.json from composer.json, prepending a path
 * repository for every package with a local checkout ahead of the VCS
 * repositories, so a development install symlinks the checkout (changes to
 * Ringside apply here at once) instead of cloning git. Everything else is
 * copied verbatim: the manifests only differ in where the packages come from.
 *
 * RingMaster does not sit beside the packages, so a checkout is looked for
 * beside this app, in $RING_MASTER_PACKAGES, and in ~/Projects/packages.
 *
 *     composer local:install
 *     composer local:update
 *
 * composer.local.json and composer.local.lock are gitignored; the committed
 * composer.json (VCS-based) is what CI installs.
 */
$root = dirname(__DIR__);

$manifest = json_decode((string) file_get_contents($root.'/composer.json'), flags: JSON_THROW_ON_ERROR);

$repositories = $manifest->repositories ?? [];

$folders = array_values(array_filter([
    dirname($root),
    getenv('RING_MASTER_PACKAGES') ?: null,
    getenv('HOME') ? getenv('HOME').'/Projects/packages' : null,
]));

$paths = [];

foreach ($repositories as $repository) {
    if (($repository->type ?? null) !== 'vcs') {
        continue;
    }

    foreach ($folders as $folder) {
        $checkout = rtrim($folder, '/').'/'.basename($repository->url, '.git');

        if (is_dir($checkout)) {
            $paths[] = ['type' => 'path', 'url' => $checkout, 'options' => ['symlink' => true]];

            break;
        }
    }
}

$manifest->repositories = [...$paths, ...$repositories];

file_put_contents(
    $root.'/composer.local.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

echo 'Regenerated composer.local.json ('.count($paths)." local path repositories)\n";

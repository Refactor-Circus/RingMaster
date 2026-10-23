<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A temporary folder with the given files (path => contents). Removed after
 * the test.
 *
 * @param  array<string, string>  $files
 */
function fixtureFolder(array $files): string
{
    $root = sys_get_temp_dir().'/ring-master-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $contents);
    }

    File::ensureDirectoryExists($root);
    test()->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

    return realpath($root) ?: $root;
}

/**
 * A workspace like the Refactor Circus one: a ring-master.yml listing two
 * packages, a spec that needs a decision in Alpha, and a folder that is not
 * listed.
 */
function fixtureWorkspace(): string
{
    $spec = 'Alpha/docs/agent/specs/2026-10-23-1430-import';

    return fixtureFolder([
        'ring-master.yml' => "workspace:\n  packages: [Alpha, Beta]\n",
        'composer.json' => '{"name": "acme/workspace"}',
        'Alpha/composer.json' => '{"name": "acme/alpha"}',
        "{$spec}/meta.yml" => "title: CSV import\nstatus: needs-decision\nticket: { provider: github, key: '#42', url: 'https://github.com/acme/alpha/issues/42' }\n",
        "{$spec}/tasks.md" => "## Group 1: Data\n- [x] 1.1 Tests\n- [ ] 1.2 Data\n",
        "{$spec}/spec.md" => "# CSV import\n\nImport <script>alert(1)</script> rows.\n",
        "{$spec}/decision.md" => "# NEEDS-DECISION\n\n1. **One row per file or per line?**\n2. **Sync or queued?**\n",
        "{$spec}/round-1/gate.md" => "VERDICT: PASS\n",
        "{$spec}/round-1/review-code.md" => "VERDICT: CHANGES\n\n- off by one\n",
        'Alpha/docs/agent/specs/2026-10-20-0900-older/meta.yml' => "title: Older\nstatus: closed\n",
        'Alpha/secret.md' => "do not show\n",
        'Beta/composer.json' => '{"name": "acme/beta"}',
        'Gamma/composer.json' => '{"name": "acme/gamma"}',
    ]);
}

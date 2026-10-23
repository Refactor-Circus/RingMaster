<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Spec;
use App\Models\Workspace;
use App\Support\ProjectDiscovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RefactorCircus\Ringside\Specs\Board;

/**
 * Mirrors every enabled workspace's projects and spec folders into the
 * database. The files stay the source of truth: a spec folder that is gone
 * from disk is gone from the board.
 */
final class ScanWorkspaces
{
    private const string LAST_SCAN = 'ring-master.last-scan';

    public function __construct(private readonly ProjectDiscovery $discovery) {}

    public function handle(): void
    {
        $this->seedFromConfig();

        foreach (Workspace::query()->where('enabled', true)->get() as $workspace) {
            $this->scan($workspace);
        }

        Cache::forever(self::LAST_SCAN, now()->getTimestamp());
    }

    /**
     * Scans only when the last scan is older than the configured window, so
     * every open board polling at once costs one scan.
     */
    public function ifStale(): void
    {
        $last = Cache::get(self::LAST_SCAN);
        $window = (int) config('ring-master.scan.stale_after', 3);

        if (! is_int($last) || now()->getTimestamp() - $last >= $window) {
            $this->handle();
        }
    }

    public function scan(Workspace $workspace): void
    {
        if (! is_dir($workspace->path)) {
            $workspace->update(['error' => 'Folder not found.', 'scanned_at' => now()]);

            return;
        }

        DB::transaction(function () use ($workspace): void {
            $kept = [];

            foreach ($this->discovery->discover($workspace->path) as $found) {
                $project = Project::query()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'path' => $found['path']],
                    ['folder' => $found['folder'], 'is_workspace' => $found['is_workspace'], 'name' => $found['folder']],
                );

                $this->importSpecs($project);
                $kept[] = $project->id;
            }

            $workspace->projects()->whereNotIn('id', $kept)->delete();
            $workspace->update(['error' => null, 'scanned_at' => now()]);
        });
    }

    private function importSpecs(Project $project): void
    {
        $board = (new Board([$project->ringside()]))->data()['projects'][0];
        $project->update(['name' => $board['name']]);
        $keys = [];

        foreach ($board['specs'] as $spec) {
            Spec::query()->updateOrCreate(['project_id' => $project->id, 'key' => $spec['id']], [
                'dir' => $spec['dir'],
                'title' => $spec['title'],
                'status' => $spec['status'],
                'column' => $spec['column'],
                'ticket' => $spec['ticket'],
                'pr' => $spec['pr'],
                'branch' => $spec['branch'],
                'tasks_done' => $spec['tasks']['done'],
                'tasks_total' => $spec['tasks']['total'],
                'packages' => $spec['packages'],
                'rounds' => $spec['rounds'],
                'decisions' => $spec['decisions'],
                'decision' => $spec['decision'],
                'questions' => $spec['questions'],
                'files' => $spec['files'],
                'spec_updated_at' => $spec['updated'] > 0 ? Carbon::createFromTimestamp($spec['updated']) : null,
            ]);
            $keys[] = $spec['id'];
        }

        $project->specs()->whereNotIn('key', $keys)->delete();
    }

    private function seedFromConfig(): void
    {
        /** @var list<string> $configured */
        $configured = config('ring-master.workspaces', []);
        $paths = [];

        foreach ($configured as $path) {
            $path = ProjectDiscovery::expand($path);
            $paths[] = $path = realpath($path) ?: $path;

            Workspace::query()->firstOrCreate(['path' => $path], ['name' => basename($path), 'from_config' => true]);
        }

        Workspace::query()->where('from_config', true)->whereNotIn('path', $paths)->delete();
    }
}

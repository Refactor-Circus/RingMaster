<?php

namespace App\Livewire;

use App\Actions\ScanWorkspaces;
use App\Models\Workspace;
use App\Support\ProjectDiscovery;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Workspaces')]
class Workspaces extends Component
{
    public string $path = '';

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'path' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! is_dir(ProjectDiscovery::expand($value))) {
                    $fail('That folder does not exist.');
                }
            }],
        ];
    }

    public function add(ScanWorkspaces $scan): void
    {
        $this->validate();

        $path = ProjectDiscovery::expand($this->path);
        $path = realpath($path) ?: $path;

        $workspace = Workspace::query()->firstOrCreate(['path' => $path], ['name' => basename($path)]);
        $workspace->update(['enabled' => true]);
        $scan->scan($workspace);

        $this->reset('path');
    }

    public function toggle(int $id): void
    {
        $workspace = Workspace::query()->findOrFail($id);
        $workspace->update(['enabled' => ! $workspace->enabled]);
    }

    public function remove(int $id): void
    {
        Workspace::query()->where('from_config', false)->findOrFail($id)->delete();
    }

    public function scanNow(ScanWorkspaces $scan): void
    {
        $scan->handle();
    }

    public function render(): View
    {
        return view('livewire.workspaces', [
            'workspaces' => Workspace::query()
                ->with(['projects' => fn ($query) => $query->withCount('specs')->orderByDesc('is_workspace')->orderBy('folder')])
                ->orderBy('name')
                ->get(),
        ]);
    }
}

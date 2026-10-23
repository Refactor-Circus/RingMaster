<?php

namespace App\Livewire;

use App\Actions\ScanWorkspaces;
use App\Enums\AttentionStatus;
use App\Models\Spec;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RefactorCircus\Ringside\Specs\Board as RingsideBoard;

class ShowSpec extends Component
{
    /** The spec is looked up on every request: a rescan may have removed it. */
    #[Locked]
    public int $specId;

    #[Url]
    public string $file = '';

    public function mount(Spec $spec): void
    {
        $this->specId = $spec->id;

        if (! in_array($this->file, self::readable($spec), true)) {
            $this->file = self::defaultFile($spec);
        }
    }

    public function refresh(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    #[On('answered')]
    public function answered(): void {}

    public function render(): View
    {
        $spec = Spec::query()->with('project')->find($this->specId);

        if ($spec === null) {
            $this->redirectRoute('board', navigate: true);

            return view('livewire.spec-gone');
        }

        return view('livewire.show-spec', [
            'spec' => $spec,
            'items' => $spec->attentionItems()->whereIn('status', [AttentionStatus::Open, AttentionStatus::Answered])->get(),
            'tasks' => $spec->tasks()->latest()->get(),
            'contents' => $this->contents($spec),
            'markdown' => str_ends_with($this->file, '.md'),
        ])->title($spec->title);
    }

    /**
     * Every file this page may show: the spec folder's documents and each
     * round's reports. Anything else is refused before it reaches the disk.
     *
     * @return list<string>
     */
    public static function readable(Spec $spec): array
    {
        $reports = [];

        foreach ($spec->rounds as $round) {
            foreach ($round['reports'] as $report) {
                $reports[] = $report['path'];
            }
        }

        return [...$spec->files, ...$reports];
    }

    private static function defaultFile(Spec $spec): string
    {
        foreach (['decision.md', 'spec.md', 'shape.md'] as $preferred) {
            if (in_array($spec->dir.'/'.$preferred, $spec->files, true) && ($preferred !== 'decision.md' || $spec->column === 'Needs you')) {
                return $spec->dir.'/'.$preferred;
            }
        }

        return $spec->files[0] ?? '';
    }

    private function contents(Spec $spec): ?string
    {
        if ($this->file === '' || ! in_array($this->file, self::readable($spec), true)) {
            return null;
        }

        $raw = (new RingsideBoard([$spec->project->ringside()]))->file(0, $this->file);

        if ($raw === null) {
            return null;
        }

        return str_ends_with($this->file, '.md')
            ? Str::markdown($raw, ['html_input' => 'escape', 'allow_unsafe_links' => false])
            : $raw;
    }
}

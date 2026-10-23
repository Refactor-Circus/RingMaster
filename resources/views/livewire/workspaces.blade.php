<div class="space-y-8">
    <x-rm.header kicker="sources" title="Workspaces">
        <x-slot:lead>Folders RingMaster watches. Spec folders stay the source of truth; RingMaster mirrors them.</x-slot:lead>
        <x-slot:actions>
            <flux:button wire:click="scanNow" icon="arrow-path" size="sm">Scan now</flux:button>
        </x-slot:actions>
    </x-rm.header>

    <form wire:submit="add" class="rm-panel flex flex-wrap items-center gap-3 p-3">
        <flux:icon name="folder-plus" variant="mini" class="ml-2 text-cyan-300" />
        <input wire:model="path" placeholder="~/Projects/packages" class="rm-mono min-w-0 flex-1 bg-transparent text-sm text-white placeholder:text-zinc-600 focus:outline-none">
        <flux:button type="submit" variant="primary" size="sm">Add folder</flux:button>
        <flux:error name="path" class="w-full px-2" />
    </form>

    @forelse ($workspaces as $workspace)
        <section wire:key="workspace-{{ $workspace->id }}" @class(['rm-panel space-y-5 p-6', 'opacity-60' => ! $workspace->enabled])>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <div class="flex items-center gap-3">
                        <span @class(['rm-orb', 'rm-tone-'.($workspace->error ? 'fail' : ($workspace->enabled ? 'live' : 'idle')), 'is-live' => $workspace->enabled && ! $workspace->error]) style="width:.6rem;height:.6rem"></span>
                        <h2 class="text-lg font-medium text-white">{{ $workspace->name }}</h2>
                    </div>
                    <div class="rm-mono text-xs text-zinc-400">{{ $workspace->path }}</div>
                    <div class="rm-mono text-[11px] text-zinc-500">
                        {{ $workspace->projects->count() }} {{ Str::plural('project', $workspace->projects->count()) }}
                        @if ($workspace->scanned_at) · scanned {{ $workspace->scanned_at->diffForHumans() }} @endif
                        @if ($workspace->from_config) · from RING_MASTER_WORKSPACES @endif
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <flux:switch :checked="$workspace->enabled" wire:click="toggle({{ $workspace->id }})" label="Enabled" />
                    @unless ($workspace->from_config)
                        <flux:button wire:click="remove({{ $workspace->id }})" wire:confirm="Remove {{ $workspace->name }} from RingMaster? Files on disk are not touched." size="sm" variant="danger" icon="trash">Remove</flux:button>
                    @endunless
                </div>
            </div>

            @if ($workspace->error)
                <div class="rm-panel rm-tone-fail rm-edge px-4 py-3 text-sm text-rose-200">{{ $workspace->error }}</div>
            @endif

            @if ($workspace->projects->isNotEmpty())
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($workspace->projects as $project)
                        <div wire:key="project-{{ $project->id }}" class="flex items-center gap-3 rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm text-white">{{ $project->folder }}</span>
                                    @if ($project->is_workspace)<x-rm.pill tone="review">workspace</x-rm.pill>@endif
                                </div>
                                <div class="rm-mono truncate text-[11px] text-zinc-500">{{ $project->name }}</div>
                            </div>
                            <span class="rm-mono text-xs text-zinc-400">{{ $project->specs_count }}<span class="text-zinc-600"> specs</span></span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <x-rm.empty icon="folder">No workspaces yet. Add a folder above.</x-rm.empty>
    @endforelse
</div>

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Workspaces</flux:heading>
            <flux:text>Folders RingMaster scans for packages and their spec folders.</flux:text>
        </div>

        <flux:button wire:click="scanNow" icon="arrow-path" size="sm">Scan now</flux:button>
    </div>

    <form wire:submit="add" class="flex flex-wrap items-start gap-3">
        <flux:input wire:model="path" placeholder="~/Projects/packages" class="max-w-md" />
        <flux:button type="submit" variant="primary">Add folder</flux:button>
        <flux:error name="path" class="w-full" />
    </form>

    @forelse ($workspaces as $workspace)
        <flux:card wire:key="workspace-{{ $workspace->id }}" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading>{{ $workspace->name }}</flux:heading>
                    <flux:text class="font-mono text-sm">{{ $workspace->path }}</flux:text>
                    <flux:text size="sm">
                        {{ $workspace->projects->count() }} {{ Str::plural('project', $workspace->projects->count()) }}
                        @if ($workspace->scanned_at) · scanned {{ $workspace->scanned_at->diffForHumans() }} @endif
                        @if ($workspace->from_config) · from RING_MASTER_WORKSPACES @endif
                    </flux:text>
                </div>

                <div class="flex items-center gap-3">
                    <flux:switch :checked="$workspace->enabled" wire:click="toggle({{ $workspace->id }})" label="Enabled" />
                    @unless ($workspace->from_config)
                        <flux:button wire:click="remove({{ $workspace->id }})" wire:confirm="Remove {{ $workspace->name }} from RingMaster? Files on disk are not touched." size="sm" variant="danger" icon="trash">Remove</flux:button>
                    @endunless
                </div>
            </div>

            @if ($workspace->error)
                <flux:callout icon="exclamation-triangle" color="red">
                    <flux:callout.text>{{ $workspace->error }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($workspace->projects->isNotEmpty())
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Folder</flux:table.column>
                        <flux:table.column>Package</flux:table.column>
                        <flux:table.column align="end">Specs</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($workspace->projects as $project)
                            <flux:table.row wire:key="project-{{ $project->id }}">
                                <flux:table.cell>{{ $project->folder }} @if ($project->is_workspace) <flux:badge size="sm" color="sky">workspace</flux:badge> @endif</flux:table.cell>
                                <flux:table.cell class="font-mono text-sm">{{ $project->name }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $project->specs_count }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @empty
        <flux:text>No workspaces yet. Add a folder above.</flux:text>
    @endforelse
</div>

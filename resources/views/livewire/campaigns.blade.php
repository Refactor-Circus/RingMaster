<div class="space-y-8">
    <x-rm.header kicker="ecosystem-wide changes" title="Campaigns">
        <x-slot:lead>One change across many packages. Each package gets a task that waits for the packages it requires, so the change lands in dependency order.</x-slot:lead>
    </x-rm.header>

    <form wire:submit="launch" class="rm-panel space-y-5 p-5 sm:p-6" data-new-campaign>
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
            <flux:input wire:model="title" label="Title" placeholder="Move every package to Pest 5" />
            <flux:select wire:model.live="workspaceId" label="Workspace">
                @foreach ($workspaces as $workspace)
                    <flux:select.option value="{{ $workspace->id }}">{{ $workspace->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:textarea wire:model="intent" label="What to change" rows="5" placeholder="What every package should change, how to tell it is done, and anything they must not change." />

        <div class="space-y-2">
            <div class="flex items-center gap-3">
                <span class="rm-kicker">packages</span>
                <button type="button" wire:click="selectAll" class="rm-mono text-xs text-cyan-300 hover:underline">all</button>
                <button type="button" wire:click="$set('projectIds', [])" class="rm-mono text-xs text-zinc-400 hover:underline">none</button>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($packages as $package)
                    <label wire:key="pkg-{{ $package->id }}" @class(['rm-tile rm-mono cursor-pointer px-3 py-1.5 text-xs', 'is-on' => in_array((string) $package->id, $projectIds, true)])>
                        <input type="checkbox" wire:model.live="projectIds" value="{{ $package->id }}" class="sr-only">{{ $package->folder }}
                    </label>
                @endforeach
            </div>
            <flux:error name="projectIds" />
        </div>

        @if ($plan)
            <div class="space-y-2" data-plan>
                <span class="rm-kicker">lands in this order</span>
                <div class="flex flex-wrap items-stretch gap-2">
                    @foreach ($plan['waves'] as $wave)
                        <div class="rm-panel rm-mono space-y-1 px-3 py-2 text-xs" wire:key="wave-{{ $loop->index }}">
                            <div class="text-zinc-500">wave {{ $loop->iteration }}</div>
                            @foreach ($wave as $project)
                                <div class="text-zinc-200">{{ $project->folder }}</div>
                            @endforeach
                        </div>
                        @unless ($loop->last)<span class="self-center text-zinc-600">→</span>@endunless
                    @endforeach
                </div>
                @if ($plan['cycle'] !== [])
                    <p class="text-xs text-amber-300">{{ collect($plan['cycle'])->pluck('folder')->implode(', ') }} require each other: they go side by side in the last wave.</p>
                @endif
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <flux:select wire:model="priority" class="max-w-40">
                @foreach (\App\Enums\TaskPriority::cases() as $priority)
                    <flux:select.option value="{{ $priority->value }}">{{ $priority->label() }} priority</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="rocket-launch">Launch campaign</flux:button>
            <flux:error name="intent" />
        </div>
    </form>

    <section class="space-y-3">
        <h2 class="rm-kicker">Campaigns</h2>
        <div class="rm-panel divide-y divide-white/5">
            @forelse ($campaigns as $campaign)
                <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm hover:bg-white/5" wire:key="campaign-{{ $campaign->id }}">
                    <x-rm.pill :tone="['running' => 'live', 'done' => 'done', 'cancelled' => 'idle'][$campaign->status->value]">{{ $campaign->status->value }}</x-rm.pill>
                    <span class="text-zinc-200">{{ $campaign->title }}</span>
                    <span class="rm-mono ml-auto text-xs text-zinc-500">{{ $campaign->done_count }}/{{ $campaign->tasks_count }} done</span>
                </a>
            @empty
                <div class="px-4 py-3 text-xs text-zinc-500">No campaigns yet.</div>
            @endforelse
        </div>
    </section>
</div>

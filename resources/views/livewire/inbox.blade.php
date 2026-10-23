<div wire:poll.5s="refresh" class="mx-auto max-w-4xl space-y-10">
    <x-rm.header kicker="human in the loop" title="Inbox">
        <x-slot:lead>Architectural calls are yours. Agents stop here instead of guessing; answer and Claude applies it to the spec folder and resumes the job.</x-slot:lead>
    </x-rm.header>

    <section class="space-y-5">
        <div class="flex items-center gap-3">
            <span class="rm-dot rm-tone-wait {{ $open->isNotEmpty() ? 'rm-dot-ping' : '' }}"></span>
            <h2 class="text-sm font-medium text-white">Needs you</h2>
            <span class="rm-mono text-xs text-zinc-500">{{ $open->flatten()->count() }}</span>
        </div>

        @forelse ($open as $items)
            @php($spec = $items->first()->spec)
            <div class="space-y-3" wire:key="spec-{{ $spec->id }}">
                <a href="{{ route('specs.show', $spec) }}" wire:navigate class="group flex items-baseline gap-3">
                    <span class="font-medium text-zinc-200 group-hover:text-cyan-300">{{ $spec->title }}</span>
                    <span class="rm-mono text-xs text-zinc-500">{{ $spec->project->folder }} · {{ $items->count() }} open</span>
                </a>
                @foreach ($items as $item)
                    <livewire:answer-item :item="$item" :wire:key="'open-'.$item->id" />
                @endforeach
            </div>
        @empty
            <x-rm.empty icon="check-circle">Nothing is waiting on you. Agents are free to work.</x-rm.empty>
        @endforelse
    </section>

    @if ($waiting->isNotEmpty())
        <section class="space-y-4">
            <div class="flex items-center gap-3">
                <span class="rm-dot rm-tone-live rm-dot-ping"></span>
                <h2 class="text-sm font-medium text-white">Answered, waiting for Claude</h2>
            </div>
            @foreach ($waiting as $item)
                <livewire:answer-item :item="$item" :show-spec="true" :wire:key="'waiting-'.$item->id" />
            @endforeach
        </section>
    @endif

    @if ($resolved->isNotEmpty())
        <section class="space-y-3">
            <h2 class="rm-kicker">Recently resolved</h2>
            <div class="rm-panel divide-y divide-white/5">
                @foreach ($resolved as $item)
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="resolved-{{ $item->id }}">
                        <x-rm.pill :tone="$item->status === \App\Enums\AttentionStatus::Applied ? 'done' : 'idle'">{{ $item->status === \App\Enums\AttentionStatus::Applied ? 'applied' : 'in a session' }}</x-rm.pill>
                        <span class="min-w-0 flex-1 truncate text-zinc-300">{{ $item->question }}</span>
                        @if ($item->answer)<span class="rm-mono truncate text-xs text-cyan-200">→ {{ Str::limit($item->answer, 50) }}</span>@endif
                        <a href="{{ route('specs.show', $item->spec) }}" wire:navigate class="rm-mono text-xs text-zinc-500 hover:text-cyan-300">{{ $item->spec->project->folder }}</a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>

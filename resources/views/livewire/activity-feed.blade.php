<section wire:poll.5s class="rm-panel flex max-h-[calc(100vh-11rem)] flex-col overflow-hidden">
    <div class="flex items-center justify-between border-b border-white/5 px-4 py-3">
        <div class="flex items-center gap-2">
            <span class="rm-dot rm-tone-live rm-dot-ping"></span>
            <span class="rm-kicker">live feed</span>
        </div>
        <span class="rm-mono text-[10px] text-zinc-500">{{ $entries->count() }} events</span>
    </div>

    <ol class="flex-1 space-y-0.5 overflow-y-auto p-2">
        @forelse ($entries as $entry)
            <li wire:key="feed-{{ $entry->id }}" class="rm-rise rm-tone-{{ \App\Livewire\ActivityFeed::tone($entry->type) }} flex gap-3 rounded-lg px-2 py-2 hover:bg-white/[0.03]">
                <span class="rm-dot mt-1.5 shrink-0" style="width:.375rem;height:.375rem"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-[13px] leading-snug text-zinc-300">{{ $entry->message }}</p>
                    <p class="rm-mono mt-0.5 truncate text-[10px] text-zinc-500">
                        {{ $entry->created_at?->diffForHumans(short: true) }}
                        @if ($entry->task)
                            · <a href="{{ route('tasks.show', $entry->task) }}" wire:navigate class="hover:text-cyan-300">{{ Str::limit($entry->task->title, 40) }}</a>
                        @endif
                    </p>
                </div>
            </li>
        @empty
            <li class="px-3 py-8 text-center text-sm text-zinc-500">Quiet so far. Queue something with <span class="rm-mono text-zinc-300">⌘K</span>.</li>
        @endforelse
    </ol>
</section>

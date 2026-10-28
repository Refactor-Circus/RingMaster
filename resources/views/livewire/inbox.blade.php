<div wire:poll.5s="refresh" class="mx-auto max-w-4xl space-y-10">
    <x-rm.header kicker="human in the loop" title="Inbox">
        <x-slot:lead>Architectural calls are yours. Agents stop here instead of guessing; answer and Claude applies it to the spec folder and resumes the job.</x-slot:lead>
    </x-rm.header>

    <section class="space-y-5">
        <div class="flex items-center gap-3">
            <span class="rm-dot rm-tone-wait {{ $open->isNotEmpty() ? 'rm-dot-ping' : '' }}"></span>
            <h2 class="text-sm font-medium text-white">Needs you</h2>
            <span class="rm-mono text-xs text-zinc-500">{{ $open->flatten()->count() + $proposals->count() + $chores->count() }}</span>
        </div>

        @foreach ($proposals as $proposal)
            <article class="rm-panel rm-tone-wait space-y-3 p-5" wire:key="proposal-{{ $proposal->id }}" data-proposal="{{ $proposal->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <x-rm.pill tone="wait" dot ping>proposed job</x-rm.pill>
                    <span class="rm-mono text-xs text-zinc-500">{{ $proposal->project->folder }}</span>
                    @foreach ($proposal->dependents as $waiter)
                        <a href="{{ route('tasks.show', $waiter) }}" wire:navigate class="rm-mono ml-auto text-xs text-zinc-500 hover:text-cyan-300">#{{ $waiter->id }} waits on it</a>
                    @endforeach
                    @foreach ($proposal->dependencies as $first)
                        <a href="{{ route('tasks.show', $first) }}" wire:navigate class="rm-mono ml-auto text-xs text-zinc-500 hover:text-cyan-300">starts after #{{ $first->id }} ({{ $first->project->folder }}) is merged</a>
                    @endforeach
                </div>
                <a href="{{ route('tasks.show', $proposal) }}" wire:navigate class="block text-lg font-medium text-white hover:text-cyan-300">{{ $proposal->title }}</a>
                <p class="whitespace-pre-line text-sm text-zinc-300">{{ $proposal->proposal_reason }}</p>
                <details class="group">
                    <summary class="rm-kicker cursor-pointer list-none hover:text-zinc-200">the task as written</summary>
                    <pre class="rm-terminal mt-2 max-h-64 overflow-auto whitespace-pre-wrap p-4 text-zinc-300">{{ $proposal->body }}</pre>
                </details>
                <div class="flex flex-wrap gap-3">
                    <flux:button wire:click="acceptProposal({{ $proposal->id }})" size="sm" icon="check" variant="primary">Accept and queue</flux:button>
                    <flux:button wire:click="declineProposal({{ $proposal->id }})" wire:confirm="Decline this job? A task waiting on it stays paused until you resume it." size="sm" variant="danger">Decline</flux:button>
                </div>
            </article>
        @endforeach

        @foreach ($chores as $chore)
            <article class="rm-panel space-y-3 p-5" wire:key="chore-{{ $chore->id }}" data-chore="{{ $chore->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <x-rm.pill tone="idle" dot>{{ $chore->kind->label() }}</x-rm.pill>
                    <span class="rm-mono text-xs text-zinc-500">{{ $chore->project->folder }} · {{ $chore->created_at?->diffForHumans(short: true) }}</span>
                </div>
                <div class="text-lg font-medium text-white">{{ $chore->title }}</div>
                @if ($chore->detail)
                    <pre class="rm-terminal max-h-48 overflow-auto whitespace-pre-wrap p-3 text-xs text-zinc-300">{{ $chore->detail }}</pre>
                @endif
                <div class="flex flex-wrap gap-3">
                    <flux:button wire:click="approveChore({{ $chore->id }})" wire:confirm="{{ $chore->kind->action() }}? This changes {{ $chore->project->folder }} on GitHub." size="sm" icon="check" variant="primary">{{ $chore->kind->action() }}</flux:button>
                    <flux:button wire:click="dismissChore({{ $chore->id }})" size="sm" variant="ghost">Leave it</flux:button>
                </div>
            </article>
        @endforeach

        @foreach ($choresDone as $chore)
            <div class="rm-mono flex flex-wrap items-center gap-2 px-1 text-xs" wire:key="chore-done-{{ $chore->id }}">
                <x-rm.pill :tone="$chore->status === \App\Enums\ChoreStatus::Done ? 'done' : 'fail'">{{ $chore->status->value }}</x-rm.pill>
                <span class="text-zinc-400">{{ $chore->project->folder }}: {{ $chore->title }}</span>
                <span class="truncate text-zinc-500">{{ $chore->result }}</span>
                @if ($chore->status === \App\Enums\ChoreStatus::Failed)
                    <button type="button" wire:click="retryChore({{ $chore->id }})" class="text-cyan-300 hover:underline">retry</button>
                @endif
            </div>
        @endforeach

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
            @if ($proposals->isEmpty() && $chores->isEmpty())
            <x-rm.empty icon="check-circle">Nothing is waiting on you. Agents are free to work.</x-rm.empty>
            @endif
        @endforelse
    </section>

    @if ($waiting->isNotEmpty())
        <section class="space-y-4">
            <div class="flex items-center gap-3">
                <span class="rm-dot rm-tone-live rm-dot-ping"></span>
                <h2 class="text-sm font-medium text-white">Answered, waiting for Claude</h2>
            </div>
            <div class="rm-mono space-y-1 text-xs text-zinc-400" data-applying>
                @foreach ($applying as $folder => $state)
                    <div><span class="text-zinc-200">{{ $folder }}</span>: {{ $state }}</div>
                @endforeach
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

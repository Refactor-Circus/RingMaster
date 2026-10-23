<div wire:poll.5s="refresh" class="space-y-8">
    <div class="space-y-4">
        <div class="rm-mono flex items-center gap-2 text-xs text-zinc-500">
            <a href="{{ route('tasks') }}" wire:navigate class="hover:text-cyan-300">Tasks</a><span>/</span><span>{{ $task->project->folder }}</span><span>/</span><span>#{{ $task->id }}</span>
        </div>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $task->title }}</h1>
            <div class="flex gap-2">
                @if ($task->status->isOpen())
                    @unless ($editing)
                        <flux:button wire:click="edit" size="sm" icon="pencil">Edit</flux:button>
                    @endunless
                    <flux:button wire:click="cancelTask" wire:confirm="Cancel this task? Its spec folder, if any, is not touched." size="sm" variant="danger">Cancel task</flux:button>
                @elseif (in_array($task->status, [\App\Enums\TaskStatus::Cancelled, \App\Enums\TaskStatus::Failed], true))
                    <flux:button wire:click="requeue" size="sm" icon="arrow-uturn-left">Queue again</flux:button>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-rm.pill :tone="$task->status->tone()" dot :ping="$task->status->tone() === 'live'">{{ $task->status->label() }}</x-rm.pill>
            <x-rm.pill :tone="match ($task->priority->value) { 3 => 'fail', 2 => 'wait', default => 'idle' }">{{ strtolower($task->priority->label()) }} priority</x-rm.pill>
            <span class="rm-mono text-xs text-zinc-400">{{ strtolower($task->kind->label()) }}@if ($task->ticket_ref) · {{ $task->ticket_ref }}@endif</span>
            @if ($task->spec)
                <a href="{{ route('specs.show', $task->spec) }}" wire:navigate class="rm-mono text-xs text-cyan-300 hover:underline">Spec: {{ $task->spec->title }} ({{ $task->spec->status }})</a>
            @elseif ($task->status->isOpen())
                <span class="rm-mono text-xs text-zinc-500">No spec yet: Claude creates one when it picks this up.</span>
            @endif
            @if ($task->claimed_by)
                <span class="rm-mono text-xs text-zinc-500">· claimed by {{ $task->claimed_by }} {{ $task->claimed_at?->diffForHumans() }}</span>
            @endif
        </div>

        @if ($task->spec)
            <x-rm.stages :status="$task->spec->status" :has-rounds="$task->spec->rounds !== []" />
        @endif
    </div>

    @if ($editing)
        <div class="rm-panel rm-signal-edge p-6">
            <form wire:submit="save" class="space-y-4">
                <flux:input wire:model="title" label="Title" />
                @if ($task->kind === \App\Enums\TaskKind::Ticket)
                    <flux:input wire:model="ticketRef" label="Ticket" />
                @endif
                <flux:textarea wire:model="body" label="Notes" rows="6" />
                <flux:select wire:model="priority" label="Priority">
                    @foreach (\App\Enums\TaskPriority::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" size="sm">Save</flux:button>
                    <flux:button wire:click="$set('editing', false)" variant="ghost" size="sm">Cancel</flux:button>
                </div>
            </form>
        </div>
    @elseif ($task->body)
        <div class="rm-panel p-6">
            <div class="rm-kicker mb-3">brief</div>
            <div class="whitespace-pre-line leading-relaxed text-zinc-200">{{ $task->body }}</div>
        </div>
    @endif

    <flux:error name="status" />

    @if ($items->isNotEmpty())
        <section class="space-y-3">
            <div class="flex items-center gap-3"><span class="rm-dot rm-tone-wait rm-dot-ping"></span><h2 class="text-sm font-medium text-white">Needs you</h2></div>
            @foreach ($items as $item)
                <livewire:answer-item :item="$item" :wire:key="'item-'.$item->id" />
            @endforeach
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <section class="space-y-3">
            <h2 class="rm-kicker">Timeline</h2>
            <ol class="relative space-y-4 border-l border-white/10 pl-6">
                @foreach ($timeline as $entry)
                    <li class="rm-rise relative rm-tone-{{ \App\Livewire\ActivityFeed::tone($entry->type) }}" wire:key="activity-{{ $entry->id }}">
                        <span class="rm-dot absolute -left-[1.68rem] top-1.5" style="width:.45rem;height:.45rem"></span>
                        <p class="text-sm text-zinc-200">{{ $entry->message }}</p>
                        <p class="rm-mono text-[10px] text-zinc-500">{{ $entry->created_at?->format('M j, H:i:s') }}</p>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($runs->isNotEmpty())
            <section class="space-y-3">
                <h2 class="rm-kicker">Runs</h2>
                @foreach ($runs as $run)
                    <a href="{{ route('runs.show', $run) }}" wire:navigate wire:key="run-{{ $run->id }}" class="rm-panel rm-panel-hover rm-tone-{{ $run->status->tone() }} flex items-center gap-3 px-4 py-3">
                        <span @class(['rm-orb', 'is-live' => $run->status->isActive()])></span>
                        <span class="rm-mono text-sm text-white">#{{ $run->id }}</span>
                        <span class="rm-mono text-xs text-zinc-400">{{ $run->status->value }}</span>
                        <span class="rm-mono ml-auto text-xs text-zinc-500">{{ $run->started_at?->diffForHumans(short: true) }}@if ($run->cost_usd !== null) · ${{ $run->cost_usd }}@endif</span>
                    </a>
                @endforeach
            </section>
        @endif
    </div>
</div>

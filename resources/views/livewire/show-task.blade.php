<div wire:poll.5s="refresh" class="space-y-8">
    <div class="space-y-4">
        <div class="rm-mono flex items-center gap-2 text-xs text-zinc-500">
            <a href="{{ route('tasks') }}" wire:navigate class="hover:text-cyan-300">Tasks</a><span>/</span><span>{{ $task->project->folder }}</span><span>/</span><span>#{{ $task->id }}</span>
        </div>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $task->title }}</h1>
            <div class="flex gap-2">
                @if ($task->status === \App\Enums\TaskStatus::Proposed)
                    <flux:button wire:click="acceptProposal" size="sm" icon="check" variant="primary">Accept and queue</flux:button>
                    <flux:button wire:click="declineProposal" wire:confirm="Decline this job? A task waiting on it stays paused until you resume it." size="sm" variant="danger">Decline</flux:button>
                @elseif ($task->status->isOpen())
                    @unless ($editing)
                        <flux:button wire:click="edit" size="sm" icon="pencil">Edit</flux:button>
                    @endunless
                    @if ($task->isPaused() || $task->isBlocked())
                        <flux:button wire:click="resume" size="sm" icon="play" variant="primary">Resume</flux:button>
                    @else
                        <flux:button wire:click="pause" wire:confirm="Pause this task? No session picks it up until you resume it, and a session working on it now is stopped. The spec keeps its progress." size="sm" icon="pause">Pause</flux:button>
                    @endif
                    <flux:button wire:click="restart" wire:confirm="Force a restart? Any session on this task is stopped, its claim and attempt count are cleared, and it goes back to the queue to start again from where its spec stands." size="sm" icon="arrow-path">Force restart</flux:button>
                    <flux:button wire:click="cancelTask" wire:confirm="Cancel this task? Its spec folder, if any, is not touched." size="sm" variant="danger">Cancel task</flux:button>
                @elseif (in_array($task->status, [\App\Enums\TaskStatus::Cancelled, \App\Enums\TaskStatus::Failed], true))
                    <flux:button wire:click="requeue" size="sm" icon="arrow-uturn-left">Queue again</flux:button>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-rm.pill :tone="$task->statusTone()" dot :ping="$task->statusTone() === 'live'">{{ $task->statusLabel() }}</x-rm.pill>
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

        @if ($waiting)
            <div class="rm-panel rm-edge rm-mono flex items-center gap-3 px-4 py-3 text-xs text-zinc-300">
                <span class="rm-dot rm-tone-idle"></span>{{ $waiting }}
            </div>
        @endif

        @if ($task->status === \App\Enums\TaskStatus::Proposed)
            <div class="rm-panel rm-tone-wait rm-edge space-y-1 p-4" data-proposal>
                <div class="rm-mono text-xs text-amber-300">Proposed by a session: nothing runs it until you accept it.</div>
                <div class="whitespace-pre-line text-sm text-zinc-200">{{ $task->proposal_reason }}</div>
            </div>
        @endif

        @if ($task->isBlocked())
            <div class="rm-panel rm-tone-wait rm-edge space-y-1 p-4" data-blocked>
                <div class="rm-mono text-xs text-amber-300">Waiting on {{ $task->dependencies->count() === 1 ? 'another task' : $task->dependencies->count().' tasks' }}: it resumes by itself once each is done or has its PR merged.</div>
                @foreach ($task->dependencies as $dependency)
                    <div class="flex items-center gap-3" wire:key="dependency-{{ $dependency->id }}">
                        <a href="{{ route('tasks.show', $dependency) }}" wire:navigate class="text-sm text-cyan-300 hover:underline">#{{ $dependency->id }} {{ $dependency->project->folder }}: {{ $dependency->title }} ({{ $dependency->statusLabel() }})</a>
                        @if ($task->status->isOpen())
                            <button type="button" wire:click="removeDependency({{ $dependency->id }})" class="rm-mono text-[10px] text-zinc-500 hover:text-rose-300">stop waiting</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($task->dependents->isNotEmpty())
            <div class="rm-panel rm-edge space-y-1 p-4" data-dependents>
                <div class="rm-mono text-xs text-zinc-400">Waiting on this task</div>
                @foreach ($task->dependents as $waiter)
                    <a href="{{ route('tasks.show', $waiter) }}" wire:navigate class="rm-mono block text-xs text-cyan-300 hover:underline">#{{ $waiter->id }} {{ $waiter->project->folder }}: {{ $waiter->title }}</a>
                @endforeach
            </div>
        @endif

        @if ($task->status->isOpen() && $candidates->isNotEmpty())
            <form wire:submit="addDependency" class="flex flex-wrap items-end gap-2" data-add-dependency>
                <flux:select wire:model="dependsOn" label="Wait on another task" size="sm" class="max-w-md">
                    <flux:select.option value="">Choose a task…</flux:select.option>
                    @foreach ($candidates as $candidate)
                        <flux:select.option :value="$candidate->id">#{{ $candidate->id }} {{ $candidate->project->folder }}: {{ $candidate->title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" size="sm" icon="link">Add</flux:button>
            </form>
            <flux:error name="depends_on" />
        @endif

        @if ($task->isPaused() && ! $task->isBlocked())
            <div class="rm-panel rm-tone-wait rm-edge space-y-1 p-4">
                <div class="rm-mono text-xs text-amber-300">Paused {{ $task->paused_at->diffForHumans() }}: no session picks it up until you resume it.</div>
                <div class="whitespace-pre-line text-sm text-zinc-200">{{ $task->pause_reason }}</div>
            </div>
        @endif

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
                        @if ($run->kind === \App\Enums\RunKind::Answers)
                            <span class="rm-mono text-xs text-zinc-500">applying your answers, then carrying on</span>
                        @endif
                        <span class="rm-mono ml-auto text-xs text-zinc-500">{{ $run->started_at?->diffForHumans(short: true) }}@if ($run->cost_usd !== null) · ${{ $run->cost_usd }}@endif</span>
                    </a>
                @endforeach
            </section>
        @endif
    </div>
</div>

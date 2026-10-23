<div class="space-y-8">
    <x-rm.header kicker="work queue" title="Tasks">
        <x-slot:lead>Ideas, tickets and specs you queued, in the order agents pick them up: highest priority, then oldest.</x-slot:lead>
        <x-slot:actions>
            <div class="rm-panel flex p-1">
                @foreach (['open' => 'Open', 'done' => 'Finished', 'all' => 'All'] as $value => $label)
                    <button type="button" wire:click="$set('show', '{{ $value }}')" @class(['rm-mono rounded-lg px-3 py-1.5 text-xs transition', 'bg-white/10 text-white' => $show === $value, 'text-zinc-400 hover:text-zinc-200' => $show !== $value])>{{ $label }}</button>
                @endforeach
            </div>
            <flux:modal.trigger name="new-task">
                <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-cyan-400 via-indigo-400 to-fuchsia-400 px-4 py-2 text-sm font-medium text-zinc-950 shadow-[0_0_30px_-8px_var(--color-signal-2)] hover:brightness-110"><flux:icon name="plus" variant="micro" />New task</button>
            </flux:modal.trigger>
        </x-slot:actions>
    </x-rm.header>

    @if ($tasks->isEmpty())
        <x-rm.empty icon="queue-list">{{ $show === 'open' ? 'Nothing queued. Add an idea, a ticket or an existing spec, or press ⌘K and describe it.' : 'No tasks here.' }}</x-rm.empty>
    @else
        <div class="space-y-2">
            @foreach ($tasks as $task)
                <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="task-{{ $task->id }}" class="rm-panel rm-panel-hover rm-edge rm-tone-{{ $task->status->tone() }} rm-rise grid items-center gap-3 px-5 py-4 sm:grid-cols-[1fr_9rem_8rem_10rem_7rem]">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-white">{{ $task->title }}</div>
                        <div class="rm-mono truncate text-[11px] text-zinc-500">{{ strtolower($task->kind->label()) }}@if ($task->ticket_ref) · {{ $task->ticket_ref }}@endif @if ($task->spec) · spec {{ $task->spec->status }}@endif</div>
                    </div>
                    <span class="rm-mono truncate text-xs text-zinc-400">{{ $task->project->folder }}</span>
                    <span class="flex items-center gap-1" title="{{ $task->priority->label() }} priority">
                        @foreach (range(0, 3) as $bar)
                            <span @class(['h-3 w-1.5 rounded-sm', 'bg-white/10' => $bar > $task->priority->value, 'bg-cyan-300 shadow-[0_0_8px_var(--color-signal-1)]' => $bar <= $task->priority->value && $task->priority->value < 2, 'bg-amber-300 shadow-[0_0_8px_var(--color-wait)]' => $bar <= $task->priority->value && $task->priority->value === 2, 'bg-rose-400 shadow-[0_0_8px_var(--color-fail)]' => $bar <= $task->priority->value && $task->priority->value === 3])></span>
                        @endforeach
                        <span class="rm-mono ml-1.5 text-[11px] text-zinc-500">{{ strtolower($task->priority->label()) }}</span>
                    </span>
                    <x-rm.pill :tone="$task->status->tone()" dot :ping="$task->status->tone() === 'live'" class="justify-self-start">{{ $task->status->label() }}</x-rm.pill>
                    <span class="rm-mono text-right text-[11px] text-zinc-500">{{ $task->updated_at?->diffForHumans(short: true) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal name="new-task" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <div>
                <div class="rm-kicker">new task</div>
                <h2 class="mt-1 text-xl font-semibold text-white">What should get built?</h2>
            </div>

            <flux:select wire:model.live="projectId" label="Project" placeholder="Choose a project">
                @foreach ($projects as $project)
                    <flux:select.option :value="$project->id">{{ $project->folder }}{{ $project->is_workspace ? ' (workspace)' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="kind" label="Start from" variant="segmented">
                @foreach (\App\Enums\TaskKind::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" />
                @endforeach
            </flux:radio.group>

            @if ($kind === 'ticket')
                <flux:input wire:model="ticketRef" label="Ticket" placeholder="PROJ-123, #42 or a URL" />
            @endif

            @if ($kind === 'spec')
                <flux:select wire:model.live="specId" label="Spec" placeholder="{{ $projectId === '' ? 'Choose a project first' : 'Choose a spec' }}">
                    @foreach ($specs as $spec)
                        <flux:select.option :value="$spec->id">{{ $spec->title }} ({{ $spec->status }})</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="title" label="Title" />
            <flux:textarea wire:model="body" label="{{ $kind === 'idea' ? 'The idea' : 'Notes' }}" rows="5" placeholder="What you want, and anything Claude should know" />

            <flux:select wire:model="priority" label="Priority">
                @foreach (\App\Enums\TaskPriority::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Queue it</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

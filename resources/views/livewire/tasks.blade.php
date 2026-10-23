<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Tasks</flux:heading>
            <flux:text>Work you queued, in the order Claude picks it up: highest priority, then oldest.</flux:text>
        </div>

        <div class="flex gap-3">
            <flux:radio.group wire:model.live="show" variant="segmented" size="sm">
                <flux:radio value="open" label="Open" />
                <flux:radio value="done" label="Finished" />
                <flux:radio value="all" label="All" />
            </flux:radio.group>

            <flux:modal.trigger name="new-task">
                <flux:button variant="primary" icon="plus" size="sm">New task</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    @if ($tasks->isEmpty())
        <flux:text>{{ $show === 'open' ? 'Nothing queued. Add an idea, a ticket or an existing spec.' : 'No tasks here.' }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Task</flux:table.column>
                <flux:table.column>Project</flux:table.column>
                <flux:table.column>Priority</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Spec</flux:table.column>
                <flux:table.column align="end">Updated</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($tasks as $task)
                    <flux:table.row wire:key="task-{{ $task->id }}">
                        <flux:table.cell>
                            <flux:link :href="route('tasks.show', $task)" wire:navigate class="font-medium">{{ $task->title }}</flux:link>
                            <flux:text size="sm">{{ $task->kind->label() }}@if ($task->ticket_ref) · {{ $task->ticket_ref }}@endif</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>{{ $task->project->folder }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>
                            @if ($task->spec)
                                <flux:link :href="route('specs.show', $task->spec)" wire:navigate>{{ $task->spec->status }}</flux:link>
                            @else
                                <flux:text size="sm">—</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $task->updated_at?->diffForHumans() }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="new-task" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-5">
            <flux:heading size="lg">New task</flux:heading>

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
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Queue it</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

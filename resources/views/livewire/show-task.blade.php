<div wire:poll.5s="refresh" class="space-y-6">
    <div class="space-y-2">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('tasks')" wire:navigate>Tasks</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $task->project->folder }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <flux:heading size="xl" level="1">{{ $task->title }}</flux:heading>

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
            <flux:badge :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
            <flux:badge :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge>
            <flux:text>{{ $task->kind->label() }}@if ($task->ticket_ref) · {{ $task->ticket_ref }}@endif</flux:text>
            @if ($task->spec)
                <flux:link :href="route('specs.show', $task->spec)" wire:navigate>Spec: {{ $task->spec->title }} ({{ $task->spec->status }})</flux:link>
            @elseif ($task->status->isOpen())
                <flux:text size="sm">No spec yet: Claude creates one when it picks this up.</flux:text>
            @endif
            @if ($task->claimed_by)
                <flux:text size="sm">Claimed by {{ $task->claimed_by }} {{ $task->claimed_at?->diffForHumans() }}</flux:text>
            @endif
        </div>
    </div>

    @if ($editing)
        <flux:card>
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
        </flux:card>
    @elseif ($task->body)
        <flux:card>
            <div class="prose-spec whitespace-pre-line text-zinc-800 dark:text-zinc-200">{{ $task->body }}</div>
        </flux:card>
    @endif

    <flux:error name="status" />

    @if ($items->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">Needs you</flux:heading>
            @foreach ($items as $item)
                <livewire:answer-item :item="$item" :wire:key="'item-'.$item->id" />
            @endforeach
        </section>
    @endif

    <section class="space-y-3">
        <flux:heading size="lg">Timeline</flux:heading>
        <ol class="space-y-2 border-l border-zinc-200 pl-4 dark:border-zinc-700">
            @foreach ($timeline as $entry)
                <li class="text-sm" wire:key="activity-{{ $entry->id }}">
                    <span class="text-zinc-400">{{ $entry->created_at?->format('M j, H:i') }}</span>
                    <span class="text-zinc-800 dark:text-zinc-200">{{ $entry->message }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>

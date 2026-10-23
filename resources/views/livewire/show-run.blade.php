<div @if ($run->status->isActive()) wire:poll.2s @endif class="space-y-6">
    <div class="space-y-2">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('runs')" wire:navigate>Runs</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>#{{ $run->id }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <flux:heading size="xl" level="1">{{ $run->task?->title ?? 'Apply answers in '.$run->project->folder }}</flux:heading>
            @if ($run->status === \App\Enums\RunStatus::Running)
                <flux:button wire:click="stopRun" wire:confirm="Stop this run?" size="sm" variant="danger">Stop</flux:button>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :color="$run->status->color()">{{ $run->status->value }}</flux:badge>
            <flux:text size="sm">{{ $run->project->folder }} · session {{ $run->session }} · started {{ $run->started_at?->diffForHumans() }}</flux:text>
            @if ($run->cost_usd !== null)
                <flux:text size="sm">${{ $run->cost_usd }}</flux:text>
            @endif
            @if ($run->task)
                <flux:link :href="route('tasks.show', $run->task)" wire:navigate>Task</flux:link>
            @endif
        </div>
    </div>

    @if ($run->summary)
        <flux:callout :color="$run->status === \App\Enums\RunStatus::Succeeded ? 'green' : 'zinc'">
            <flux:callout.text class="whitespace-pre-line">{{ $run->summary }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-2">
        @forelse ($entries as $entry)
            <div @class(['text-sm', 'font-mono text-zinc-500' => $entry['type'] === 'tool', 'whitespace-pre-line text-zinc-800 dark:text-zinc-200' => $entry['type'] === 'text', 'font-medium text-green-700 dark:text-green-400' => $entry['type'] === 'result', 'font-medium text-red-600' => $entry['type'] === 'error'])>{{ $entry['type'] === 'tool' ? '→ ' : '' }}{{ $entry['text'] }}</div>
        @empty
            <flux:text>{{ $run->status->isActive() ? 'Waiting for the session to start…' : 'This run wrote no log.' }}</flux:text>
        @endforelse
    </flux:card>

    @if ($stderr !== '')
        <details>
            <summary class="cursor-pointer text-sm text-zinc-500">stderr</summary>
            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded bg-zinc-50 p-3 text-sm dark:bg-zinc-900">{{ $stderr }}</pre>
        </details>
    @endif
</div>

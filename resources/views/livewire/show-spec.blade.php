<div wire:poll.3s="refresh" class="space-y-6">
    <div class="space-y-2">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('board')" wire:navigate>Board</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $spec->project->folder }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:heading size="xl" level="1">{{ $spec->title }}</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge>{{ $spec->status }}</flux:badge>
            <flux:badge color="zinc">{{ $spec->column }}</flux:badge>
            @if ($spec->ticketUrl())
                <flux:link :href="$spec->ticketUrl()" external>{{ $spec->ticketKey() ?? 'Ticket' }}</flux:link>
            @elseif ($spec->ticketKey())
                <flux:text>{{ $spec->ticketKey() }}</flux:text>
            @endif
            @foreach ($spec->pullRequests() as $pr)
                <flux:link :href="$pr['url']" external>{{ $pr['label'] }}</flux:link>
            @endforeach
            @if ($spec->branch)
                <flux:text class="font-mono text-sm">{{ $spec->branch }}</flux:text>
            @endif
            @if ($spec->tasks_total > 0)
                <flux:text>Tasks {{ $spec->tasks_done }}/{{ $spec->tasks_total }}</flux:text>
            @endif
        </div>

        @foreach ($tasks as $task)
            <flux:text size="sm">Task: <flux:link :href="route('tasks.show', $task)" wire:navigate>{{ $task->title }}</flux:link> ({{ $task->status->label() }})</flux:text>
        @endforeach

        @if ($spec->questions > 0)
            <flux:callout icon="question-mark-circle" color="amber">
                <flux:callout.heading>{{ $spec->questions }} {{ Str::plural('question', $spec->questions) }} waiting for you</flux:callout.heading>
                @if ($items->isEmpty())
                    <flux:callout.text>This spec lists them only in decision.md, so answer them with /ring-master-decide in {{ $spec->project->folder }}.</flux:callout.text>
                @endif
            </flux:callout>
        @endif
    </div>

    @if ($items->isNotEmpty())
        <section class="space-y-3">
            @foreach ($items as $item)
                <livewire:answer-item :item="$item" :wire:key="'item-'.$item->id" />
            @endforeach
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <nav class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="sm">Files</flux:heading>
                @foreach ($spec->files as $path)
                    <button type="button" data-path="{{ $path }}" x-on:click="$wire.set('file', $el.dataset.path)" @class(['block w-full truncate rounded px-2 py-1 text-left text-sm', 'bg-zinc-100 font-medium dark:bg-zinc-700' => $file === $path, 'hover:bg-zinc-50 dark:hover:bg-zinc-700/50' => $file !== $path])>{{ basename($path) }}</button>
                @endforeach
            </div>

            @foreach ($spec->rounds as $round)
                <div class="space-y-1" wire:key="round-{{ $round['n'] }}">
                    <flux:heading size="sm">Round {{ $round['n'] }}</flux:heading>
                    @foreach ($round['reports'] as $report)
                        <button type="button" data-path="{{ $report['path'] }}" x-on:click="$wire.set('file', $el.dataset.path)" @class(['flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm', 'bg-zinc-100 font-medium dark:bg-zinc-700' => $file === $report['path'], 'hover:bg-zinc-50 dark:hover:bg-zinc-700/50' => $file !== $report['path']])>
                            <span>{{ $report['name'] }}</span>
                            <flux:badge size="sm" :color="match ($report['verdict']) { 'pass' => 'green', 'changes' => 'red', default => 'zinc' }">{{ $report['verdict'] ?? '—' }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <flux:card class="min-w-0">
            @if ($contents === null)
                <flux:text>{{ $file === '' ? 'This spec has no files yet.' : 'That file is not readable here.' }}</flux:text>
            @else
                <flux:text size="sm" class="mb-4 font-mono text-zinc-400">{{ $file }}</flux:text>
                @if ($markdown)
                    <div class="prose-spec text-zinc-800 dark:text-zinc-200">{!! $contents !!}</div>
                @else
                    <pre class="overflow-x-auto text-sm"><code>{{ $contents }}</code></pre>
                @endif
            @endif
        </flux:card>
    </div>
</div>

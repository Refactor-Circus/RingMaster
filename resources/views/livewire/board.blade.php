<div wire:poll.3s="refresh" class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Board</flux:heading>
            <flux:text>Every job across your workspaces, read from the spec folders.</flux:text>
        </div>

        <div class="flex flex-wrap gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search specs" size="sm" class="w-56" />

            <flux:select wire:model.live="project" size="sm" class="w-56">
                <flux:select.option value="">All projects</flux:select.option>
                @foreach ($projects as $option)
                    <flux:select.option :value="$option->id">{{ $option->folder }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($projects->isEmpty())
        <flux:callout icon="folder-plus">
            <flux:callout.heading>No projects yet</flux:callout.heading>
            <flux:callout.text>Add a workspace folder on the <flux:link :href="route('workspaces')" wire:navigate>Workspaces</flux:link> page, or set RING_MASTER_WORKSPACES in .env.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex gap-4 overflow-x-auto pb-4">
        <section class="flex w-72 shrink-0 flex-col gap-3" data-column="Queue">
            <div class="flex items-center justify-between px-1">
                <flux:heading size="sm">Queue</flux:heading>
                <flux:badge size="sm">{{ $queue->count() }}</flux:badge>
            </div>

            @forelse ($queue as $task)
                <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="task-{{ $task->id }}" class="block">
                    <flux:card size="sm" class="space-y-2 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <flux:heading class="leading-snug">{{ $task->title }}</flux:heading>
                        <flux:text size="sm">{{ $task->project->folder }} · {{ $task->kind->label() }}@if ($task->ticket_ref) · {{ $task->ticket_ref }}@endif</flux:text>
                        <div class="flex gap-1">
                            <flux:badge size="sm" :color="$task->status->color()">{{ $task->status->label() }}</flux:badge>
                            <flux:badge size="sm" :color="$task->priority->color()">{{ $task->priority->label() }}</flux:badge>
                        </div>
                    </flux:card>
                </a>
            @empty
                <a href="{{ route('tasks') }}" wire:navigate class="block rounded-lg border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-400 hover:text-zinc-600 dark:border-zinc-700">Queue a task</a>
            @endforelse
        </section>

        @foreach ($columns as $column)
            @php($cards = $specs->get($column, collect()))

            <section class="flex w-72 shrink-0 flex-col gap-3" data-column="{{ $column }}">
                <div class="flex items-center justify-between px-1">
                    <flux:heading size="sm">{{ $column }}</flux:heading>
                    <flux:badge size="sm" :color="$column === 'Needs you' && $cards->isNotEmpty() ? 'amber' : 'zinc'">{{ $cards->count() }}</flux:badge>
                </div>

                @forelse ($cards as $spec)
                    <a href="{{ route('specs.show', $spec) }}" wire:navigate wire:key="spec-{{ $spec->id }}" class="block">
                        <flux:card size="sm" class="space-y-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                            <div>
                                <flux:heading class="leading-snug">{{ $spec->title }}</flux:heading>
                                <flux:text size="sm">{{ $spec->project->folder }}@if ($spec->ticketKey()) · {{ $spec->ticketKey() }}@endif</flux:text>
                            </div>

                            <div class="flex flex-wrap gap-1">
                                <flux:badge size="sm">{{ $spec->status }}</flux:badge>
                                @if ($spec->questions > 0)
                                    <flux:badge size="sm" color="amber" icon="question-mark-circle">{{ $spec->questions }} {{ Str::plural('question', $spec->questions) }}</flux:badge>
                                @endif
                                @foreach ($spec->packages as $package)
                                    <flux:badge size="sm" color="sky">{{ $package }}</flux:badge>
                                @endforeach
                            </div>

                            @if ($spec->tasks_total > 0)
                                <div class="space-y-1">
                                    <flux:text size="sm">Tasks {{ $spec->tasks_done }}/{{ $spec->tasks_total }}</flux:text>
                                    <flux:progress :value="(int) round($spec->tasks_done / $spec->tasks_total * 100)" />
                                </div>
                            @endif

                            @if ($reports = $spec->latestReports())
                                <div class="flex flex-wrap gap-1">
                                    <flux:text size="sm" class="mr-1">Round {{ count($spec->rounds) }}</flux:text>
                                    @foreach ($reports as $report)
                                        <flux:badge size="sm" :color="match ($report['verdict']) { 'pass' => 'green', 'changes' => 'red', default => 'zinc' }">{{ $report['name'] }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif

                            @if ($spec->spec_updated_at)
                                <flux:text size="sm" class="text-zinc-400">Updated {{ $spec->spec_updated_at->diffForHumans() }}</flux:text>
                            @endif
                        </flux:card>
                    </a>
                @empty
                    <div class="rounded-lg border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-400 dark:border-zinc-700">Nothing here</div>
                @endforelse
            </section>
        @endforeach
    </div>
</div>

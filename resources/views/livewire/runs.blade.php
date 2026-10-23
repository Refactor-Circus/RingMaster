<div wire:poll.3s class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Runs</flux:heading>
            <flux:text>Headless Claude sessions the runner started for queued tasks and answers to apply.</flux:text>
        </div>

        <flux:button wire:click="togglePause" :variant="$paused ? 'primary' : 'filled'" :icon="$paused ? 'play' : 'pause'" size="sm">{{ $paused ? 'Resume runner' : 'Pause runner' }}</flux:button>
    </div>

    <flux:card class="flex flex-wrap items-center gap-x-6 gap-y-2">
        <div class="flex items-center gap-2">
            <flux:badge :color="$alive ? ($paused ? 'amber' : 'green') : 'zinc'">{{ $alive ? ($paused ? 'Paused' : 'Running') : 'Not running' }}</flux:badge>
            @unless ($alive)
                <flux:text size="sm">Start it with <code class="font-mono">php artisan ring-master:work</code></flux:text>
            @endunless
        </div>
        <flux:text size="sm">Up to {{ $concurrency }} at a time</flux:text>
        <flux:text size="sm">${{ number_format($spent, 2) }} in the last 24 hours</flux:text>
    </flux:card>

    @if ($runs->isEmpty())
        <flux:text>No runs yet.</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Run</flux:table.column>
                <flux:table.column>For</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Started</flux:table.column>
                <flux:table.column align="end">Cost</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($runs as $run)
                    <flux:table.row wire:key="run-{{ $run->id }}">
                        <flux:table.cell><flux:link :href="route('runs.show', $run)" wire:navigate>#{{ $run->id }}</flux:link></flux:table.cell>
                        <flux:table.cell>
                            @if ($run->task)
                                <flux:link :href="route('tasks.show', $run->task)" wire:navigate>{{ $run->task->title }}</flux:link>
                            @else
                                Apply answers
                            @endif
                            <flux:text size="sm">{{ $run->project->folder }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$run->status->color()">{{ $run->status->value }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $run->started_at?->diffForHumans() }}@if ($run->finished_at) · {{ $run->started_at?->diffForHumans($run->finished_at, true) }}@endif</flux:table.cell>
                        <flux:table.cell align="end">{{ $run->cost_usd !== null ? '$'.$run->cost_usd : '—' }}</flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($run->status === \App\Enums\RunStatus::Running)
                                <flux:button wire:click="stopRun({{ $run->id }})" wire:confirm="Stop run #{{ $run->id }}? Its task goes back to the queue unless it already has a spec." size="xs" variant="danger">Stop</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>

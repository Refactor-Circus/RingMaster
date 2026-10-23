<div wire:poll.3s class="space-y-8">
    <x-rm.header kicker="autonomous sessions" title="Runs">
        <x-slot:lead>Headless Claude sessions working your queue and applying your answers, each limited to the tools you allow.</x-slot:lead>
        <x-slot:actions>
            <flux:button wire:click="togglePause" :variant="$paused ? 'primary' : 'filled'" :icon="$paused ? 'play' : 'pause'" size="sm">{{ $paused ? 'Resume runner' : 'Pause runner' }}</flux:button>
        </x-slot:actions>
    </x-rm.header>

    @php($state = $alive ? ($paused ? 'wait' : 'live') : 'idle')
    <div class="rm-panel rm-tone-{{ $state }} {{ $state === 'live' ? 'rm-signal-edge' : '' }} grid items-center gap-6 p-6 sm:grid-cols-[auto_1fr_auto]">
        <div class="relative grid size-20 place-items-center">
            <span class="absolute inset-0 rounded-full border border-[color:var(--rm-tone)] opacity-30"></span>
            <span class="absolute inset-3 rounded-full border border-[color:var(--rm-tone)] opacity-20 {{ $state === 'live' ? 'animate-ping' : '' }}" style="animation-duration: 3s"></span>
            <span @class(['rm-orb', 'is-live' => $state === 'live']) style="width:1.75rem;height:1.75rem"></span>
        </div>
        <div>
            <div class="text-2xl font-semibold text-white">{{ $alive ? ($paused ? 'Paused' : 'Running') : 'Not running' }}</div>
            <div class="rm-mono mt-1 text-xs text-zinc-400">
                @if ($alive)
                    up to {{ $concurrency }} at a time · ${{ number_format($spent, 2) }} in the last 24 hours
                @else
                    start it with <span class="text-cyan-300">php artisan ring-master:work</span>
                @endif
            </div>
        </div>
        <div class="rm-mono text-right text-xs text-zinc-500">{{ $runs->where('status', \App\Enums\RunStatus::Running)->count() }} live</div>
    </div>

    @if ($runs->isEmpty())
        <x-rm.empty icon="cpu-chip">No runs yet.</x-rm.empty>
    @else
        <div class="space-y-2">
            @foreach ($runs as $run)
                <div wire:key="run-{{ $run->id }}" class="rm-panel rm-panel-hover rm-tone-{{ $run->status->tone() }} grid items-center gap-3 px-5 py-3 sm:grid-cols-[4rem_1fr_7rem_9rem_5rem_4.5rem]">
                    <a href="{{ route('runs.show', $run) }}" wire:navigate class="rm-mono flex items-center gap-2 text-sm text-white hover:text-cyan-300"><span @class(['rm-orb', 'is-live' => $run->status->isActive()]) style="width:.55rem;height:.55rem"></span>#{{ $run->id }}</a>
                    <div class="min-w-0">
                        @if ($run->task)
                            <a href="{{ route('tasks.show', $run->task) }}" wire:navigate class="block truncate text-sm text-zinc-200 hover:text-cyan-300">{{ $run->task->title }}</a>
                        @else
                            <span class="block text-sm text-zinc-200">Apply answers</span>
                        @endif
                        <span class="rm-mono text-[11px] text-zinc-500">{{ $run->project->folder }}</span>
                    </div>
                    <x-rm.pill :tone="$run->status->tone()" dot :ping="$run->status->isActive()">{{ $run->status->value }}</x-rm.pill>
                    <span class="rm-mono text-[11px] text-zinc-500">{{ $run->started_at?->diffForHumans(short: true) }}@if ($run->finished_at) · {{ $run->started_at?->diffForHumans($run->finished_at, true, true) }}@endif</span>
                    <span class="rm-mono text-right text-xs text-zinc-300">{{ $run->cost_usd !== null ? '$'.$run->cost_usd : '—' }}</span>
                    <span class="text-right">
                        @if ($run->status === \App\Enums\RunStatus::Running)
                            <flux:button wire:click="stopRun({{ $run->id }})" wire:confirm="Stop run #{{ $run->id }}? Its task goes back to the queue unless it already has a spec." size="xs" variant="danger">Stop</flux:button>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>

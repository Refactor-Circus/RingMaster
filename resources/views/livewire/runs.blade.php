<div wire:poll.3s class="space-y-8">
    <x-rm.header kicker="autonomous sessions" title="Runs">
        <x-slot:lead>Headless Claude sessions working your queue and applying your answers, each limited to the tools you allow.</x-slot:lead>
        <x-slot:actions>
            <flux:button wire:click="plan" icon="light-bulb" size="sm" title="A read-only session reads every roadmap, standards feedback and post-mortem, and suggests jobs for your Inbox">Plan now</flux:button>
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
                    ${{ number_format($spent, 2) }} in the last 24 hours
                    @if ($backoff)
                        · <span class="text-amber-300">backing off after a usage limit until {{ \Illuminate\Support\Carbon::createFromTimestamp($backoff)->format('H:i') }}</span>
                    @elseif ($overloaded)
                        · <span class="text-amber-300">machine busy: waiting for the load to drop before starting more</span>
                    @endif
                    @if ($night)
                        · <span @class(['text-amber-300' => $night['exhausted'], 'text-cyan-300' => ! $night['exhausted']]) data-night>night shift: ${{ number_format($night['spent'], 2) }}@if ($night['budget']) of ${{ number_format($night['budget'], 2) }}@endif{{ $night['exhausted'] ? ', budget spent: nothing new until morning' : '' }}</span>
                    @endif
                @else
                    start it with <span class="text-cyan-300">php artisan ring-master:work</span>
                @endif
            </div>
        </div>
        <div class="flex flex-col items-end gap-2">
            <div class="rm-mono flex items-center gap-2 text-xs text-zinc-400" data-concurrency>
                <flux:button wire:click="concurrency(-1)" size="xs" icon="minus" :disabled="$concurrency <= 1" aria-label="Fewer at once" />
                <span class="text-white">{{ $concurrency }}</span> at a time
                <flux:button wire:click="concurrency(1)" size="xs" icon="plus" aria-label="More at once" />
            </div>
            <div class="rm-mono text-xs text-zinc-500">{{ $runs->where('status', \App\Enums\RunStatus::Running)->count() }} live</div>
        </div>
    </div>

    @if ($alive && $capacity['live'] < $capacity['limit'] && ($capacity['hold'] || $capacity['waiting'] !== []))
        <section class="rm-panel space-y-3 p-5" data-capacity>
            <div class="flex items-center gap-3">
                <span class="rm-dot rm-tone-idle"></span>
                <h2 class="text-sm font-medium text-white">Why {{ $capacity['live'] }} of {{ $capacity['limit'] }}</h2>
            </div>
            @if ($capacity['hold'])
                <p class="text-xs text-amber-300">{{ $capacity['hold'] }}</p>
            @endif
            <div class="space-y-2">
                @foreach ($capacity['waiting'] as $reason => $jobs)
                    <div class="grid gap-1 text-xs sm:grid-cols-[18rem_1fr]" wire:key="capacity-{{ md5($reason) }}">
                        <span class="text-zinc-300">{{ $reason }} <span class="rm-mono text-zinc-500">· {{ count($jobs) }}</span></span>
                        <span class="rm-mono text-zinc-500 [overflow-wrap:anywhere]">{{ implode(' · ', $jobs) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($refused->isNotEmpty())
        <section class="rm-panel rm-tone-wait space-y-3 p-5" data-refused>
            <div class="flex items-center gap-3">
                <span class="rm-dot rm-tone-wait"></span>
                <h2 class="text-sm font-medium text-white">Refused commands, last 7 days</h2>
            </div>
            <p class="text-xs text-zinc-400">Sessions tried these and the allowlist said no. Allow the ones that are safe in <span class="rm-mono">ring-master.runner.allowed_tools</span>; the rest point at a skill to fix.</p>
            <div class="space-y-1">
                @foreach ($refused as $suggestion => $row)
                    <div class="rm-mono grid gap-3 text-xs sm:grid-cols-[3rem_18rem_1fr]" wire:key="refused-{{ md5($suggestion) }}">
                        <span class="text-amber-300">×{{ $row['count'] }}</span>
                        <span class="text-zinc-200 [overflow-wrap:anywhere]">{{ $suggestion }}</span>
                        <span class="truncate text-zinc-500" title="{{ $row['example'] }}">{{ $row['example'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

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
                            <span class="block text-sm text-zinc-200">{{ match ($run->kind) { \App\Enums\RunKind::Plan => 'Plan the portfolio', \App\Enums\RunKind::Improve => 'Analyse the factory', \App\Enums\RunKind::Replay => 'Replay '.$run->replay_of, default => 'Apply answers' } }}</span>
                        @endif
                        <span class="rm-mono text-[11px] text-zinc-500">{{ $run->project->folder }}</span>
                    </div>
                    <span class="flex flex-col items-start gap-1">
                        <x-rm.pill :tone="$run->status->tone()" dot :ping="$run->status->isActive()">{{ $run->status->value }}</x-rm.pill>
                        @if ($run->failure)
                            <span class="rm-mono text-[10px] text-zinc-500">{{ $run->failure->label() }}</span>
                        @endif
                    </span>
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

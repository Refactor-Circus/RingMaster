<div wire:poll.5s class="space-y-8">
    <x-rm.header kicker="the factory improving itself" title="Factory">
        <x-slot:lead>The analyst reads failed runs, refused commands, escalations and post-mortems, and proposes changes to the factory. Ringside changes are built on a branch and replayed against recent jobs before you adopt them.</x-slot:lead>
        <x-slot:actions>
            <flux:button wire:click="analyse" icon="beaker" size="sm" :disabled="$analysing || $waiting">{{ $analysing ? 'Analysing…' : ($waiting ? 'Analysis requested' : 'Analyse the factory') }}</flux:button>
        </x-slot:actions>
    </x-rm.header>

    @if ($waiting)
        <div class="rm-panel rm-tone-wait flex items-center gap-2 px-4 py-3 text-sm text-zinc-300" data-waiting>
            <span class="rm-dot rm-tone-wait"></span>{{ $waiting }}
        </div>
    @endif

    @forelse ($improvements as $improvement)
        @php($tone = ['open' => 'wait', 'building' => 'live', 'evaluating' => 'live', 'evaluated' => 'review', 'adopted' => 'done', 'dismissed' => 'idle'][$improvement->status->value])
        <article class="rm-panel rm-tone-{{ $tone }} space-y-4 p-5" wire:key="improvement-{{ $improvement->id }}" data-improvement="{{ $improvement->id }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-rm.pill :tone="$tone" dot :ping="in_array($improvement->status->value, ['building', 'evaluating'], true)">{{ $improvement->status->label() }}</x-rm.pill>
                <span class="rm-mono text-xs text-zinc-500">{{ $improvement->target }} · {{ $improvement->created_at?->diffForHumans(short: true) }}</span>
                @if ($improvement->task)
                    <a href="{{ route('tasks.show', $improvement->task) }}" wire:navigate class="rm-mono ml-auto text-xs text-cyan-300 hover:underline">task #{{ $improvement->task->id }} · {{ $improvement->task->statusLabel() }}</a>
                @endif
            </div>
            <h3 class="text-lg font-medium text-white">{{ $improvement->title }}</h3>
            <p class="whitespace-pre-line text-sm text-zinc-300">{{ $improvement->rationale }}</p>
            <details class="group">
                <summary class="rm-kicker cursor-pointer list-none hover:text-zinc-200">evidence and the change</summary>
                <pre class="rm-terminal mt-2 max-h-64 overflow-auto whitespace-pre-wrap p-4 text-zinc-300">{{ $improvement->evidence }}</pre>
                <pre class="rm-terminal mt-2 max-h-64 overflow-auto whitespace-pre-wrap p-4 text-zinc-200">{{ $improvement->change }}</pre>
            </details>

            @if (! empty($improvement->results['specs']) || ! empty($improvement->results['pending']))
                <div class="space-y-2" data-results>
                    <div class="rm-kicker">replays against {{ $improvement->branch }}</div>
                    <div class="rm-mono grid grid-cols-[1fr_auto_auto_auto] gap-x-4 gap-y-1 text-xs">
                        <span class="text-zinc-500">job</span><span class="text-zinc-500">result</span><span class="text-zinc-500">rounds</span><span class="text-zinc-500">cost</span>
                        @foreach ($improvement->results['specs'] ?? [] as $key => $row)
                            <span class="truncate text-zinc-300" title="{{ $key }}">{{ $row['project'] }}: {{ $row['title'] }}</span>
                            <span @class(['text-emerald-300' => ($row['replay']['status'] ?? '') === 'approved', 'text-amber-300' => ($row['replay']['status'] ?? '') !== 'approved'])>{{ $row['replay']['status'] ?? '?' }} <span class="text-zinc-500">(was {{ $row['baseline']['escalated'] ? 'escalated' : 'approved' }})</span></span>
                            <span class="text-zinc-200">{{ $row['replay']['rounds'] ?? '–' }} <span class="text-zinc-500">vs {{ $row['baseline']['rounds'] }}</span></span>
                            <span class="text-zinc-200">${{ number_format((float) ($row['replay']['cost'] ?? 0), 2) }} <span class="text-zinc-500">vs ${{ number_format((float) $row['baseline']['cost'], 2) }}</span></span>
                        @endforeach
                        @foreach ($improvement->results['pending'] ?? [] as $pending)
                            <span class="text-zinc-500">{{ $pending['key'] }}</span><span class="text-zinc-500">waiting</span><span></span><span></span>
                        @endforeach
                    </div>
                    @if ($replaying->has($improvement->id))
                        <a href="{{ route('runs.show', $replaying[$improvement->id]) }}" wire:navigate class="rm-mono text-xs text-cyan-300 hover:underline">replaying now: run #{{ $replaying[$improvement->id]->id }}</a>
                    @endif
                </div>
            @endif

            @if (in_array($improvement->status, $open, true))
                <div class="flex flex-wrap gap-3">
                    @if ($improvement->status === \App\Enums\ImprovementStatus::Open)
                        <flux:button wire:click="accept({{ $improvement->id }})" size="sm" icon="check" variant="primary">{{ $improvement->target === 'ringside' ? 'Accept: build it' : 'Adopt (I made the change)' }}</flux:button>
                    @endif
                    @if (in_array($improvement->status, [\App\Enums\ImprovementStatus::Building, \App\Enums\ImprovementStatus::Evaluated], true) && $improvement->branch)
                        <flux:button wire:click="evaluate({{ $improvement->id }})" wire:confirm="Replay up to {{ config('ring-master.improve.replay_specs') }} recent jobs against {{ $improvement->branch }}? Capped at ${{ config('ring-master.improve.budget_usd') }} in all; nothing is pushed." size="sm" icon="play">{{ $improvement->status === \App\Enums\ImprovementStatus::Evaluated ? 'Evaluate again' : 'Evaluate' }}</flux:button>
                    @endif
                    @if (in_array($improvement->status, [\App\Enums\ImprovementStatus::Building, \App\Enums\ImprovementStatus::Evaluated], true))
                        <flux:button wire:click="adopt({{ $improvement->id }})" size="sm" icon="hand-thumb-up">Adopt</flux:button>
                    @endif
                    @if ($improvement->status !== \App\Enums\ImprovementStatus::Evaluating)
                        <flux:button wire:click="dismiss({{ $improvement->id }})" size="sm" variant="ghost">Dismiss</flux:button>
                    @endif
                </div>
            @endif
        </article>
    @empty
        <x-rm.empty icon="beaker">No proposals yet. “Analyse the factory” starts the analyst (the runner must be running).</x-rm.empty>
    @endforelse
</div>

<div class="mx-auto max-w-4xl space-y-8">
    <x-rm.header kicker="good morning" title="Briefing">
        <x-slot:lead>What shipped, what to review (quickest first), what needs you, and what it cost. Ticked PRs are reviewed in one go; merging stays yours on GitHub.</x-slot:lead>
        <x-slot:actions>
            <flux:button wire:click="briefNow" icon="sparkles" size="sm">Brief me now</flux:button>
        </x-slot:actions>
    </x-rm.header>

    @if ($night['active'])
        <div class="rm-panel rm-tone-live rm-mono flex flex-wrap items-center gap-3 px-4 py-3 text-xs text-zinc-300" data-night>
            <flux:icon name="moon" variant="micro" class="text-cyan-300" />
            Night shift on: ${{ number_format($night['spent'], 2) }}@if ($night['budget']) of ${{ number_format($night['budget'], 2) }}@endif spent tonight
        </div>
    @endif

    @if (! $briefing)
        <x-rm.empty icon="sun">No briefing yet. One is made each morning at {{ config('ring-master.night_shift.briefing_at') }} while the runner runs, or press “Brief me now”.</x-rm.empty>
    @else
        <div class="rm-mono flex flex-wrap items-center gap-2 text-xs text-zinc-500">
            <span>#{{ $briefing->id }} · {{ $briefing->created_at?->toDayDateTimeString() }} · since {{ $briefing->since->diffForHumans() }}</span>
            <span class="ml-auto flex flex-wrap gap-2">
                @foreach ($older as $other)
                    <a href="{{ route('briefing.show', $other) }}" wire:navigate @class(['hover:text-cyan-300', 'text-white' => $other->id === $briefing->id])>{{ $other->created_at?->format('D j') }}</a>
                @endforeach
            </span>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <x-rm.stat label="shipped" :value="count($data['shipped'])" tone="done" />
            <x-rm.stat label="to review" :value="count($data['review'])" tone="review" />
            <x-rm.stat label="need you" :value="$data['needs']['count']" tone="wait" />
            <x-rm.stat label="spent" :value="'$'.number_format((float) $data['runs']['cost'], 2)" tone="live" />
        </div>

        <section class="space-y-4" data-review>
            <div class="flex items-center gap-3">
                <span class="rm-dot rm-tone-review"></span>
                <h2 class="text-sm font-medium text-white">Ready for your review</h2>
                @if ($data['review'] !== [])
                    <flux:button wire:click="reviewTicked" wire:confirm="Review the ticked PRs on GitHub? Each is approved, or labelled ring-master:reviewed where GitHub refuses an approval. Nothing is merged." size="sm" icon="check" variant="primary" class="ml-auto" :disabled="$ticked === []">Review {{ count($ticked) }} ticked</flux:button>
                @endif
            </div>
            @foreach ($reviewed as $line)
                <p class="rm-mono text-xs text-emerald-300">{{ $line }}</p>
            @endforeach
            @forelse ($data['review'] as $row)
                @php($tone = ['low' => 'done', 'medium' => 'wait', 'high' => 'fail'][$row['risk']['level']])
                <article class="rm-panel rm-tone-{{ $tone }} space-y-3 p-5" wire:key="review-{{ $row['id'] }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <input type="checkbox" wire:model.live="ticked" value="{{ $row['id'] }}" class="rounded border-white/20 bg-black/30" aria-label="Review {{ $row['title'] }}">
                        <x-rm.pill :tone="$tone" dot>{{ $row['risk']['level'] }} risk · {{ $row['risk']['score'] }}</x-rm.pill>
                        <span class="rm-mono text-xs text-zinc-500">{{ $row['project'] }} · +{{ $row['risk']['additions'] }} −{{ $row['risk']['deletions'] }} · {{ $row['risk']['files'] }} files</span>
                        <span class="ml-auto flex gap-2">
                            @foreach ($row['prs'] as $pr)
                                <a href="{{ $pr['url'] }}" target="_blank" rel="noopener" class="rm-mono text-xs text-cyan-300 hover:underline">{{ $pr['label'] }} ↗</a>
                            @endforeach
                        </span>
                    </div>
                    <a href="{{ route('specs.show', $row['id']) }}" wire:navigate class="block text-lg font-medium text-white hover:text-cyan-300">{{ $row['title'] }}</a>
                    <p class="text-xs text-zinc-400">{{ implode(' · ', $row['risk']['reasons']) }}</p>
                    @if ($row['brief'])
                        <details class="group" @if ($row['risk']['level'] !== 'low') open @endif>
                            <summary class="rm-kicker cursor-pointer list-none hover:text-zinc-200">reviewer brief</summary>
                            <div class="prose-spec mt-2">{!! Str::markdown($row['brief'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        </details>
                    @endif
                </article>
            @empty
                <x-rm.empty icon="check-circle">No PRs waiting for you.</x-rm.empty>
            @endforelse
        </section>

        @if ($data['needs']['count'] > 0)
            <section class="space-y-3" data-needs>
                <div class="flex items-center gap-3">
                    <span class="rm-dot rm-tone-wait"></span>
                    <h2 class="text-sm font-medium text-white">Needs you</h2>
                    <a href="{{ route('inbox') }}" wire:navigate class="rm-mono ml-auto text-xs text-cyan-300 hover:underline">open the Inbox →</a>
                </div>
                <div class="rm-panel divide-y divide-white/5">
                    @foreach ($data['needs']['questions'] as $question)
                        <a href="{{ route('specs.show', $question['spec_id']) }}" wire:navigate class="block space-y-1 px-4 py-3 hover:bg-white/5" wire:key="q-{{ $question['id'] }}">
                            <div class="text-sm text-zinc-200"><span class="rm-mono text-xs text-zinc-500">{{ $question['project'] }} · {{ $question['kind'] }}</span> {{ $question['question'] }}</div>
                            @if ($question['precedent'])
                                <div class="rm-mono text-xs text-cyan-200">precedent: {{ Str::limit($question['precedent']['answer'], 80) }} ({{ $question['precedent']['project'] }}, {{ $question['precedent']['decided_at'] }})</div>
                            @endif
                        </a>
                    @endforeach
                    @foreach (['proposals' => 'proposed job(s)', 'chores' => 'chore(s)', 'improvements' => 'factory improvement(s)'] as $key => $label)
                        @if ($data['needs'][$key] > 0)
                            <div class="rm-mono px-4 py-3 text-xs text-zinc-300">{{ $data['needs'][$key] }} {{ $label }}</div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($data['shipped'] !== [])
            <section class="space-y-3">
                <h2 class="rm-kicker">Shipped since {{ $briefing->since->diffForHumans() }}</h2>
                <div class="rm-panel divide-y divide-white/5">
                    @foreach ($data['shipped'] as $spec)
                        <a href="{{ route('specs.show', $spec['id']) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm hover:bg-white/5" wire:key="shipped-{{ $spec['id'] }}">
                            <x-rm.pill :tone="$spec['status'] === 'merged' ? 'done' : 'review'">{{ $spec['status'] }}</x-rm.pill>
                            <span class="text-zinc-200">{{ $spec['title'] }}</span>
                            <span class="rm-mono ml-auto text-xs text-zinc-500">{{ $spec['project'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid gap-6 sm:grid-cols-2">
            <div class="space-y-3">
                <h2 class="rm-kicker">Runs</h2>
                <div class="rm-panel rm-mono space-y-1 p-4 text-xs text-zinc-300">
                    <div>{{ $data['runs']['count'] }} run(s), {{ $data['runs']['succeeded'] }} succeeded, ${{ number_format((float) $data['runs']['cost'], 2) }}</div>
                    @if ($data['runs']['night'])
                        <div>last night: ${{ number_format((float) $data['runs']['night']['spent'], 2) }}@if ($data['runs']['night']['budget']) of ${{ number_format((float) $data['runs']['night']['budget'], 2) }}@endif</div>
                    @endif
                    @foreach ($data['runs']['failures'] as $class => $count)
                        <div class="text-amber-300">{{ $class }} ×{{ $count }}</div>
                    @endforeach
                </div>
            </div>
            <div class="space-y-3">
                <h2 class="rm-kicker">Next in the queue</h2>
                <div class="rm-panel divide-y divide-white/5">
                    @forelse ($data['next'] as $task)
                        <a href="{{ route('tasks.show', $task['id']) }}" wire:navigate class="block px-4 py-2 text-sm text-zinc-300 hover:bg-white/5" wire:key="next-{{ $task['id'] }}"><span class="rm-mono text-xs text-zinc-500">{{ $task['project'] }}</span> {{ $task['title'] }}</a>
                    @empty
                        <div class="px-4 py-3 text-xs text-zinc-500">The queue is empty.</div>
                    @endforelse
                </div>
            </div>
        </section>

        @if ($data['signals'] !== [])
            <section class="space-y-3">
                <h2 class="rm-kicker">New signals</h2>
                <div class="rm-panel divide-y divide-white/5">
                    @foreach ($data['signals'] as $signal)
                        <a href="{{ $signal['url'] ?? '#' }}" target="_blank" rel="noopener" class="block px-4 py-2 text-sm text-zinc-300 hover:bg-white/5"><span class="rm-mono text-xs text-zinc-500">{{ $signal['project'] }} · {{ $signal['kind'] }}</span> {{ $signal['title'] }}</a>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</div>

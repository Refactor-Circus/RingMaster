@php($escalation = $item->kind === \App\Enums\AttentionKind::Escalation)
@php($tone = $escalation ? 'fail' : 'wait')
<article @class(['rm-panel rm-tone-'.$tone, 'rm-signal-edge' => $editing]) data-attention="{{ $item->id }}">
    <div class="space-y-5 p-5 sm:p-6">
        <div class="flex flex-wrap items-center gap-2">
            <x-rm.pill :tone="$tone" dot :ping="$item->status === \App\Enums\AttentionStatus::Open">{{ $escalation ? 'escalation' : 'decision' }}</x-rm.pill>
            @if ($item->source)
                <span class="rm-kicker">from {{ $item->source }}</span>
            @endif
            @if ($showSpec)
                <a href="{{ route('specs.show', $item->spec) }}" wire:navigate class="rm-mono ml-auto truncate text-xs text-zinc-500 hover:text-cyan-300">{{ $item->spec->project->folder }} / {{ $item->spec->title }}</a>
            @endif
        </div>

        <h3 class="text-lg font-medium leading-snug tracking-tight text-white sm:text-xl">{{ $item->question }}</h3>

        @if ($item->evidence)
            <details @if ($escalation) open @endif class="group">
                <summary class="rm-kicker flex cursor-pointer list-none items-center gap-2 hover:text-zinc-200">
                    <flux:icon name="chevron-right" variant="micro" class="transition group-open:rotate-90" />{{ $escalation ? 'reasons and findings' : 'evidence' }}
                </summary>
                <pre class="rm-terminal mt-2 max-h-64 overflow-auto whitespace-pre-wrap p-4 text-zinc-300">{{ $item->evidence }}</pre>
            </details>
        @endif

        @if ($editing)
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($item->orderedOptions() as $option)
                        <label wire:key="option-{{ $loop->index }}" @class(['rm-tile', 'is-on' => $choice === $option])>
                            <input type="radio" wire:model.live="choice" value="{{ $option }}" class="sr-only">
                            <span class="flex items-start justify-between gap-3">
                                <span class="text-sm text-zinc-100">{{ $option }}</span>
                                @if ($option === $item->recommendation)
                                    <span class="rm-pill rm-tone-live shrink-0"><flux:icon name="sparkles" variant="micro" class="size-3" />AI recommends</span>
                                @endif
                            </span>
                            @if ($option === $item->recommendation)
                                <span class="sr-only">Recommended</span>
                            @endif
                        </label>
                    @endforeach
                    @unless ($escalation)
                        <label @class(['rm-tile', 'is-on' => $choice === \App\Livewire\AnswerItem::OTHER])>
                            <input type="radio" wire:model.live="choice" value="{{ \App\Livewire\AnswerItem::OTHER }}" class="sr-only">
                            <span class="flex items-center gap-2 text-sm text-zinc-400"><flux:icon name="pencil" variant="micro" />Something else</span>
                        </label>
                    @endunless
                </div>

                @if ($choice === \App\Livewire\AnswerItem::OTHER)
                    <textarea wire:model="other" rows="2" placeholder="Your own answer" class="rm-terminal w-full p-3 text-zinc-100 placeholder:text-zinc-600 focus:border-cyan-400/50 focus:outline-none"></textarea>
                @endif

                <flux:error name="answer" />

                <textarea wire:model="note" rows="2" placeholder="{{ $escalation ? 'What to change (required to send it back)' : 'Why, in your words: recorded in decisions.md (optional)' }}" class="w-full rounded-xl border border-white/10 bg-black/30 p-3 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-cyan-400/50 focus:outline-none"></textarea>
                <flux:error name="note" />

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="group relative inline-flex items-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-cyan-400 via-indigo-400 to-fuchsia-400 px-4 py-2 text-sm font-medium text-zinc-950 shadow-[0_0_30px_-8px_var(--color-signal-2)] transition hover:brightness-110">
                        <flux:icon name="check" variant="micro" />Save answer
                    </button>
                    @if ($item->status === \App\Enums\AttentionStatus::Answered)
                        <button type="button" wire:click="cancel" class="rm-mono text-xs text-zinc-500 hover:text-zinc-200">Keep the current answer</button>
                    @endif
                    <span class="rm-kicker ml-auto hidden sm:inline">claude applies it to the spec folder</span>
                </div>
            </form>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <div class="rm-terminal flex-1 px-4 py-3 text-sm text-white"><span class="text-cyan-300">›</span> {{ $item->answer ?? 'No answer recorded here' }}</div>
                @switch ($item->status)
                    @case (\App\Enums\AttentionStatus::Answered)
                        <x-rm.pill tone="live" dot ping>Waiting for Claude to apply</x-rm.pill>
                        @break
                    @case (\App\Enums\AttentionStatus::Applied)
                        <x-rm.pill tone="done" dot>Applied {{ $item->applied_at?->diffForHumans() }}</x-rm.pill>
                        @break
                    @case (\App\Enums\AttentionStatus::Closed)
                        <x-rm.pill tone="idle">Answered in a session</x-rm.pill>
                        @break
                @endswitch
            </div>
            @if ($item->note)
                <p class="text-sm text-zinc-400">{{ $item->note }}</p>
            @endif
            @if ($item->status === \App\Enums\AttentionStatus::Answered)
                <button type="button" wire:click="edit" class="rm-mono inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-cyan-300"><flux:icon name="pencil" variant="micro" />Change</button>
            @endif
        @endif
    </div>
</article>

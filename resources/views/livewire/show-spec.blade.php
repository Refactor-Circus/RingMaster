<div wire:poll.3s="refresh" class="space-y-8">
    <div class="space-y-4">
        <div class="rm-mono flex items-center gap-2 text-xs text-zinc-500">
            <a href="{{ route('board') }}" wire:navigate class="hover:text-cyan-300">Board</a><span>/</span><span>{{ $spec->project->folder }}</span><span>/</span><span class="truncate">{{ $spec->key }}</span>
        </div>

        <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $spec->title }}</h1>

        <div class="flex flex-wrap items-center gap-3">
            <x-rm.stages :status="$spec->status" :has-rounds="$spec->rounds !== []" />
            <x-rm.pill :tone="$spec->tone()" dot>{{ $spec->status }}</x-rm.pill>
        </div>

        <div class="rm-mono flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-400">
            @if ($spec->ticketUrl())
                <a href="{{ $spec->ticketUrl() }}" target="_blank" rel="noopener" class="text-cyan-300 hover:underline">{{ $spec->ticketKey() ?? 'Ticket' }} ↗</a>
            @elseif ($spec->ticketKey())
                <span>{{ $spec->ticketKey() }}</span>
            @endif
            @foreach ($spec->pullRequests() as $pr)
                <a href="{{ $pr['url'] }}" target="_blank" rel="noopener" class="text-cyan-300 hover:underline">{{ $pr['label'] }} ↗</a>
            @endforeach
            @if ($spec->branch)<span>⎇ {{ $spec->branch }}</span>@endif
            @if ($spec->tasks_total > 0)<span>Tasks {{ $spec->tasks_done }}/{{ $spec->tasks_total }}</span>@endif
            @foreach ($tasks as $task)
                <a href="{{ route('tasks.show', $task) }}" wire:navigate class="hover:text-cyan-300">task: {{ $task->title }} ({{ $task->statusLabel() }})</a>
            @endforeach
        </div>

        @if ($spec->questions > 0)
            <div class="rm-panel rm-tone-wait rm-edge flex items-center gap-3 px-4 py-3">
                <span class="rm-dot rm-dot-ping"></span>
                <span class="text-sm text-amber-100">{{ $spec->questions }} {{ Str::plural('question', $spec->questions) }} waiting for you</span>
                @if ($items->isEmpty())
                    <span class="text-sm text-zinc-400">This spec lists them only in decision.md, so answer them with /ring-master-decide in {{ $spec->project->folder }}.</span>
                @endif
            </div>
        @endif
    </div>

    @if ($items->isNotEmpty())
        <section class="max-w-4xl space-y-3">
            @foreach ($items as $item)
                <livewire:answer-item :item="$item" :wire:key="'item-'.$item->id" />
            @endforeach
        </section>
    @endif

    @if ($spec->status === 'in-review' && $items->isEmpty())
        <section class="max-w-4xl">
            <form wire:submit="sendBack" class="rm-panel space-y-3 p-5" data-send-back>
                <div class="rm-kicker">Send back to the factory</div>
                <p class="text-sm text-zinc-400">
                    Claude adds your note to tasks.md as a rework group and relaunches the build on the same branch; the PR picks up the new commits.
                    @if ($spec->unbuiltTasks() > 0) {{ $spec->unbuiltTasks() }} of {{ $spec->tasks_total }} tasks are still unbuilt. @endif
                </p>
                <textarea wire:model="sendBackNote" rows="2" placeholder="What to change" class="w-full rounded-xl border border-white/10 bg-black/30 p-3 text-sm text-zinc-100 placeholder:text-zinc-600 focus:border-cyan-400/50 focus:outline-none"></textarea>
                <flux:error name="note" />
                <button type="submit" class="rm-mono inline-flex items-center gap-2 rounded-xl border border-white/10 px-4 py-2 text-sm text-zinc-100 hover:border-cyan-400/50"><flux:icon name="arrow-uturn-left" variant="micro" />Send back</button>
            </form>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-[15rem_1fr]">
        <nav class="space-y-6">
            <div class="space-y-1">
                <div class="rm-kicker px-2 pb-1">Files</div>
                @foreach ($spec->files as $path)
                    <button type="button" data-path="{{ $path }}" x-on:click="$wire.set('file', $el.dataset.path)" @class(['rm-mono flex w-full items-center gap-2 truncate rounded-lg px-2 py-1.5 text-left text-xs transition', 'bg-white/[0.07] text-white' => $file === $path, 'text-zinc-400 hover:bg-white/[0.03] hover:text-zinc-200' => $file !== $path])>
                        <flux:icon name="document-text" variant="micro" class="shrink-0 {{ $file === $path ? 'text-cyan-300' : 'text-zinc-600' }}" />{{ basename($path) }}
                    </button>
                @endforeach
            </div>

            @foreach ($spec->rounds as $round)
                <div class="space-y-1" wire:key="round-{{ $round['n'] }}">
                    <div class="rm-kicker px-2 pb-1">Round {{ $round['n'] }}</div>
                    @foreach ($round['reports'] as $report)
                        @php($verdictTone = match ($report['verdict']) { 'pass' => 'done', 'changes' => 'fail', default => 'live' })
                        <button type="button" data-path="{{ $report['path'] }}" x-on:click="$wire.set('file', $el.dataset.path)" @class(['rm-mono flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition', 'bg-white/[0.07] text-white' => $file === $report['path'], 'text-zinc-400 hover:bg-white/[0.03] hover:text-zinc-200' => $file !== $report['path']])>
                            <span>{{ $report['name'] }}</span>
                            <x-rm.pill :tone="$verdictTone">{{ $report['verdict'] ?? '—' }}</x-rm.pill>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <article class="rm-panel min-w-0 p-6 sm:p-8">
            @if ($contents === null)
                <p class="text-sm text-zinc-400">{{ $file === '' ? 'This spec has no files yet.' : 'That file is not readable here.' }}</p>
            @else
                <div class="rm-mono mb-6 flex items-center gap-2 text-[11px] text-zinc-500"><flux:icon name="document-text" variant="micro" />{{ $file }}</div>
                @if ($markdown)
                    <div class="prose-spec">{!! $contents !!}</div>
                @else
                    <pre class="rm-terminal overflow-x-auto p-4 text-zinc-300"><code>{{ $contents }}</code></pre>
                @endif
            @endif
        </article>
    </div>
</div>

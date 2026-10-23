<div @if ($run->status->isActive()) wire:poll.2s @endif class="space-y-6">
    <div class="space-y-4">
        <div class="rm-mono flex items-center gap-2 text-xs text-zinc-500">
            <a href="{{ route('runs') }}" wire:navigate class="hover:text-cyan-300">Runs</a><span>/</span><span>#{{ $run->id }}</span>
        </div>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="text-3xl font-semibold tracking-tight text-white">{{ $run->task?->title ?? 'Apply answers in '.$run->project->folder }}</h1>
            @if ($run->status === \App\Enums\RunStatus::Running)
                <flux:button wire:click="stopRun" wire:confirm="Stop this run?" size="sm" variant="danger">Stop</flux:button>
            @endif
        </div>
        <div class="rm-mono flex flex-wrap items-center gap-3 text-xs text-zinc-400">
            <x-rm.pill :tone="$run->status->tone()" dot :ping="$run->status->isActive()">{{ $run->status->value }}</x-rm.pill>
            <span>{{ $run->project->folder }}</span><span>session {{ $run->session }}</span><span>started {{ $run->started_at?->diffForHumans() }}</span>
            @if ($run->cost_usd !== null)<span class="text-zinc-200">${{ $run->cost_usd }}</span>@endif
            @if ($run->task)<a href="{{ route('tasks.show', $run->task) }}" wire:navigate class="text-cyan-300 hover:underline">Task</a>@endif
        </div>
    </div>

    @if ($run->summary)
        <div class="rm-panel rm-tone-{{ $run->status->tone() }} rm-edge whitespace-pre-line p-5 text-sm leading-relaxed text-zinc-200">{{ $run->summary }}</div>
    @endif

    <div class="rm-terminal overflow-hidden">
        <div class="flex items-center gap-2 border-b border-white/5 px-4 py-2.5">
            <span class="size-2.5 rounded-full bg-rose-400/70"></span><span class="size-2.5 rounded-full bg-amber-300/70"></span><span class="size-2.5 rounded-full bg-emerald-400/70"></span>
            <span class="ml-3 text-[11px] text-zinc-500">claude -p · {{ $run->project->folder }}</span>
        </div>
        <div class="max-h-[65vh] space-y-1.5 overflow-y-auto p-4">
            @forelse ($entries as $entry)
                <div @class([
                    'whitespace-pre-line',
                    'text-zinc-500' => $entry['type'] === 'tool',
                    'text-zinc-200' => $entry['type'] === 'text',
                    'text-emerald-300' => $entry['type'] === 'result',
                    'text-rose-300' => $entry['type'] === 'error',
                ])>@if ($entry['type'] === 'tool')<span class="text-cyan-400">→ </span>@elseif ($entry['type'] === 'text')<span class="text-fuchsia-300">◆ </span>@else<span>■ </span>@endif{{ $entry['text'] }}</div>
            @empty
                <div class="text-zinc-500">{{ $run->status->isActive() ? 'Waiting for the session to start…' : 'This run wrote no log.' }}</div>
            @endforelse
            @if ($run->status->isActive())
                <div class="rm-caret text-zinc-500"></div>
            @endif
        </div>
    </div>

    @if ($stderr !== '')
        <details>
            <summary class="rm-kicker cursor-pointer">stderr</summary>
            <pre class="rm-terminal mt-2 overflow-x-auto whitespace-pre-wrap p-4 text-zinc-400">{{ $stderr }}</pre>
        </details>
    @endif
</div>

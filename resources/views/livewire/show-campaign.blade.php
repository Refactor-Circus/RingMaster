<div wire:poll.5s class="space-y-8">
    <x-rm.header kicker="campaign #{{ $campaign->id }}" :title="$campaign->title">
        <x-slot:lead>{{ $done }} of {{ $campaign->tasks->count() }} packages done · {{ $campaign->status->value }}</x-slot:lead>
        <x-slot:actions>
            @if ($campaign->status === \App\Enums\CampaignStatus::Running)
                <flux:button wire:click="cancelCampaign" wire:confirm="Cancel this campaign? Its open tasks are cancelled; spec folders and PRs are not touched." size="sm" variant="danger">Cancel campaign</flux:button>
            @endif
        </x-slot:actions>
    </x-rm.header>

    @php($total = max(1, $campaign->tasks->count()))
    <div class="h-2 overflow-hidden rounded-full bg-white/5"><div class="h-full bg-gradient-to-r from-cyan-400 to-fuchsia-400" style="width: {{ (int) round($done / $total * 100) }}%"></div></div>

    <div class="flex flex-wrap items-start gap-3" data-waves>
        @foreach ($waves as $wave)
            <div class="min-w-48 flex-1 space-y-2" wire:key="wave-{{ $loop->index }}">
                <div class="rm-kicker">wave {{ $loop->iteration }}</div>
                @foreach ($wave as $task)
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="rm-panel rm-panel-hover rm-tone-{{ $task->statusTone() }} block space-y-1 px-3 py-2" wire:key="task-{{ $task->id }}">
                        <div class="flex items-center gap-2">
                            <span class="rm-dot"></span>
                            <span class="text-sm text-white">{{ $task->project->folder }}</span>
                            <span class="rm-mono ml-auto text-xs text-zinc-400">{{ $task->statusLabel() }}</span>
                        </div>
                        @if ($task->spec)
                            <div class="rm-mono truncate text-xs text-zinc-500">{{ $task->spec->status }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>

    <details class="rm-panel p-5">
        <summary class="rm-kicker cursor-pointer list-none">what every package changes</summary>
        <pre class="rm-terminal mt-3 whitespace-pre-wrap p-4 text-zinc-300">{{ $campaign->intent }}</pre>
    </details>
</div>

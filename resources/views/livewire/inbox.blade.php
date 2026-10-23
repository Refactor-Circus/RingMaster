<div wire:poll.5s="refresh" class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">Inbox</flux:heading>
        <flux:text>What your jobs are waiting on you for. Claude applies your answers to the spec folder and resumes the job.</flux:text>
    </div>

    <section class="space-y-4">
        <flux:heading size="lg">Needs you</flux:heading>

        @forelse ($open as $items)
            @php($spec = $items->first()->spec)
            <div class="space-y-3" wire:key="spec-{{ $spec->id }}">
                <div class="flex items-center gap-2">
                    <flux:link :href="route('specs.show', $spec)" wire:navigate class="font-medium">{{ $spec->title }}</flux:link>
                    <flux:text size="sm">{{ $spec->project->folder }} · {{ $items->count() }} open</flux:text>
                </div>
                @foreach ($items as $item)
                    <livewire:answer-item :item="$item" :wire:key="'open-'.$item->id" />
                @endforeach
            </div>
        @empty
            <flux:callout icon="check-circle" color="green">
                <flux:callout.text>Nothing is waiting on you.</flux:callout.text>
            </flux:callout>
        @endforelse
    </section>

    @if ($waiting->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">Answered, waiting for Claude</flux:heading>
            @foreach ($waiting as $item)
                <livewire:answer-item :item="$item" :show-spec="true" :wire:key="'waiting-'.$item->id" />
            @endforeach
        </section>
    @endif

    @if ($resolved->isNotEmpty())
        <section class="space-y-2">
            <flux:heading size="lg">Recently resolved</flux:heading>
            @foreach ($resolved as $item)
                <div class="flex flex-wrap items-center gap-2 text-sm" wire:key="resolved-{{ $item->id }}">
                    <flux:badge size="sm" :color="$item->status === \App\Enums\AttentionStatus::Applied ? 'green' : 'zinc'">{{ $item->status === \App\Enums\AttentionStatus::Applied ? 'Applied' : 'In a session' }}</flux:badge>
                    <flux:text>{{ Str::limit($item->question, 100) }}</flux:text>
                    @if ($item->answer)
                        <flux:text class="font-medium">→ {{ Str::limit($item->answer, 60) }}</flux:text>
                    @endif
                    <flux:link :href="route('specs.show', $item->spec)" wire:navigate>{{ $item->spec->project->folder }}</flux:link>
                </div>
            @endforeach
        </section>
    @endif
</div>

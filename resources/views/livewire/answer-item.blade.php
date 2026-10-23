@php($escalation = $item->kind === \App\Enums\AttentionKind::Escalation)
<flux:card class="space-y-4" data-attention="{{ $item->id }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-1">
            @if ($showSpec)
                <flux:link :href="route('specs.show', $item->spec)" wire:navigate class="text-sm">{{ $item->spec->project->folder }} · {{ $item->spec->title }}</flux:link>
            @endif
            <flux:heading class="leading-snug">{{ $item->question }}</flux:heading>
        </div>

        <div class="flex shrink-0 gap-1">
            <flux:badge size="sm" :color="$escalation ? 'red' : 'amber'">{{ $escalation ? 'Escalation' : 'Decision' }}</flux:badge>
            @if ($item->source)
                <flux:badge size="sm">{{ $item->source }}</flux:badge>
            @endif
        </div>
    </div>

    @if ($item->evidence)
        <details @if ($escalation) open @endif>
            <summary class="cursor-pointer text-sm text-zinc-500">{{ $escalation ? 'Reasons and findings' : 'Evidence' }}</summary>
            <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-zinc-50 p-3 text-sm dark:bg-zinc-900">{{ $item->evidence }}</pre>
        </details>
    @endif

    @if ($editing)
        <form wire:submit="save" class="space-y-4">
            <flux:radio.group wire:model.live="choice" label="Your answer">
                @foreach ($item->orderedOptions() as $option)
                    <flux:radio :value="$option" :label="$option" :description="$option === $item->recommendation ? 'Recommended' : null" />
                @endforeach
                @unless ($escalation)
                    <flux:radio :value="\App\Livewire\AnswerItem::OTHER" label="Something else" />
                @endunless
            </flux:radio.group>

            @if ($choice === \App\Livewire\AnswerItem::OTHER)
                <flux:textarea wire:model="other" rows="2" placeholder="Your own answer" />
            @endif

            <flux:error name="answer" />

            <flux:textarea wire:model="note" rows="2" :label="$escalation ? 'Note (required to send it back)' : 'Why (optional)'" :placeholder="$escalation ? 'What to change' : 'Your rationale, recorded in decisions.md'" />

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" size="sm">Save answer</flux:button>
                @if ($item->status === \App\Enums\AttentionStatus::Answered)
                    <flux:button wire:click="cancel" size="sm" variant="ghost">Keep the current answer</flux:button>
                @endif
            </div>
        </form>
    @else
        <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <flux:text class="font-medium text-zinc-800 dark:text-zinc-100">{{ $item->answer ?? 'No answer recorded here' }}</flux:text>
                @switch ($item->status)
                    @case (\App\Enums\AttentionStatus::Answered)
                        <flux:badge size="sm" color="sky">Waiting for Claude to apply</flux:badge>
                        @break
                    @case (\App\Enums\AttentionStatus::Applied)
                        <flux:badge size="sm" color="green">Applied {{ $item->applied_at?->diffForHumans() }}</flux:badge>
                        @break
                    @case (\App\Enums\AttentionStatus::Closed)
                        <flux:badge size="sm">Answered in a session</flux:badge>
                        @break
                @endswitch
            </div>
            @if ($item->note)
                <flux:text size="sm">{{ $item->note }}</flux:text>
            @endif
            @if ($item->status === \App\Enums\AttentionStatus::Answered)
                <flux:button wire:click="edit" size="sm" variant="ghost" icon="pencil">Change</flux:button>
            @endif
        </div>
    @endif
</flux:card>

@props(['href', 'icon', 'current' => false, 'badge' => null, 'tone' => 'idle', 'count' => null])
<a href="{{ $href }}" wire:navigate @class(['rm-rail-link', 'is-current' => $current])>
    <flux:icon :name="$icon" variant="outline" class="size-5 shrink-0" />
    <span class="text-sm font-medium">{{ $slot }}</span>
    @if ($count)
        {{-- Kept current by <livewire:nav-counter />. --}}
        <span x-data="{ n: {{ (int) $badge }} }" x-on:ring-master-counts.window="n = $event.detail.{{ $count }}" x-show="n > 0" x-text="n" @if (! $badge) style="display: none" @endif class="rm-pill rm-tone-{{ $tone }} ml-auto">{{ $badge }}</span>
    @elseif ($badge)
        <span class="rm-pill rm-tone-{{ $tone }} ml-auto">{{ $badge }}</span>
    @endif
</a>

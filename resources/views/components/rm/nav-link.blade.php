@props(['href', 'icon', 'current' => false, 'badge' => null, 'tone' => 'idle'])
<a href="{{ $href }}" wire:navigate @class(['rm-rail-link', 'is-current' => $current])>
    <flux:icon :name="$icon" variant="outline" class="size-5 shrink-0" />
    <span class="text-sm font-medium">{{ $slot }}</span>
    @if ($badge)
        <span class="rm-pill rm-tone-{{ $tone }} ml-auto">{{ $badge }}</span>
    @endif
</a>

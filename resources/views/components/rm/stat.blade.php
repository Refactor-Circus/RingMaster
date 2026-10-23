@props(['label', 'value', 'tone' => 'idle', 'live' => false, 'href' => null])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['rm-panel rm-panel-hover rm-tone-'.$tone, 'block px-4 py-3']) }}>
    <div class="flex items-center gap-2">
        <span @class(['rm-dot', 'rm-dot-ping' => $live])></span>
        <span class="rm-kicker">{{ $label }}</span>
    </div>
    <div class="rm-mono mt-1 text-2xl font-medium text-white tabular-nums">{{ $value }}</div>
</{{ $tag }}>

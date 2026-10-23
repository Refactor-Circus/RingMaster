@props(['tone' => 'idle', 'dot' => false, 'ping' => false])
<span {{ $attributes->class(['rm-pill', 'rm-tone-'.$tone]) }}>@if ($dot)<span @class(['rm-dot', 'rm-dot-ping' => $ping]) style="width:.4rem;height:.4rem"></span>@endif{{ $slot }}</span>

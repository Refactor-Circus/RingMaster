@props(['kicker' => null, 'title', 'core' => false])
<header {{ $attributes->class(['relative flex flex-wrap items-end justify-between gap-6']) }}>
    @if ($core)
        <div class="rm-core hidden md:block" aria-hidden="true"><span class="ring-a"></span><span class="ring-b"></span><span class="ring-c"></span><span class="core"></span></div>
    @endif
    <div class="min-w-0 space-y-2">
        @if ($kicker)
            <div class="rm-kicker">{{ $kicker }}</div>
        @endif
        <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl"><span class="rm-gradient-text">{{ $title }}</span></h1>
        @isset($lead)
            <p class="max-w-2xl text-sm text-zinc-400">{{ $lead }}</p>
        @endisset
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
    @endisset
</header>

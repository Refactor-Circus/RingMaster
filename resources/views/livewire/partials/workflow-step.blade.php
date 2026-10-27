<div @class(['rm-panel flex min-w-0 flex-col gap-2 p-4', 'border-dashed opacity-60' => ! $step['on']]) data-step="{{ $step['key'] }}">
    <div class="flex items-center justify-between gap-2">
        <h3 class="flex min-w-0 items-baseline gap-2 text-sm font-semibold text-white">@isset($n)<span class="rm-mono text-[10px] font-normal text-zinc-500">{{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }}</span>@endisset{{ $step['name'] }}</h3>
        @if (! $step['on'])
            <x-rm.pill tone="idle">off</x-rm.pill>
        @elseif ($step['role'])
            <span class="rm-mono text-[10px] text-zinc-500">{{ $step['role'] }}</span>
        @endif
    </div>
    @if ($step['does'])
        <p class="text-xs text-zinc-400">{{ $step['does'] }}</p>
    @endif
    @if (! $step['on'] && $step['enable'])
        <p class="rm-mono text-[10px] text-zinc-500 [overflow-wrap:anywhere]">turn on: {{ $step['enable'] }}</p>
    @else
        <dl class="space-y-1.5">
            @foreach ($step['facts'] as $f)
                <div>
                    <dt class="rm-mono text-[10px] uppercase tracking-wider text-zinc-500">{{ $f['label'] }}</dt>
                    <dd @class(['flex min-w-0 items-start gap-1.5 text-xs', 'text-cyan-200' => $f['set'], 'text-zinc-300' => ! $f['set']])>@if ($f['set'])<span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-cyan-300" title="set in ring-master.yml"></span>@endif<span class="min-w-0 [overflow-wrap:anywhere]">{{ $f['value'] }}</span></dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>

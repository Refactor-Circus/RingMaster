@props(['status', 'hasRounds' => false])
@php
    $stages = ['ticket', 'spec', 'build', 'review', 'done'];
    $at = match ($status) {
        'ticketed' => 0,
        'shaping', 'written', 'approved' => 1,
        'building', 'in-progress' => 2,
        'in-review' => 3,
        'merged', 'closed', 'done', 'imported' => 4,
        default => $hasRounds ? 2 : 1,
    };
    $waiting = in_array($status, ['needs-decision', 'escalated'], true);
@endphp
<ol class="flex items-center gap-1.5" aria-label="Stage">
    @foreach ($stages as $i => $stage)
        <li class="flex items-center gap-1.5">
            <span @class([
                'rm-mono flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] uppercase tracking-wider',
                'border-emerald-400/30 text-emerald-300/80' => $i < $at,
                'rm-tone-'.($waiting ? 'wait' : ($at === 4 ? 'done' : 'live')).' border-[color:var(--rm-tone)] text-white shadow-[0_0_20px_-6px_var(--rm-tone)]' => $i === $at,
                'border-white/10 text-zinc-600' => $i > $at,
            ])>
                @if ($i === $at)<span @class(['rm-dot', 'rm-dot-ping' => $at < 4]) style="width:.35rem;height:.35rem"></span>@elseif ($i < $at)✓@endif
                {{ $stage }}
            </span>
            @if (! $loop->last)<span @class(['h-px w-4', 'bg-emerald-400/40' => $i < $at, 'bg-white/10' => $i >= $at])></span>@endif
        </li>
    @endforeach
</ol>

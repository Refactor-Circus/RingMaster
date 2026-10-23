@props(['icon' => 'sparkles'])
<div {{ $attributes->class(['rm-panel flex flex-col items-center gap-3 px-6 py-10 text-center']) }}>
    <div class="grid size-11 place-items-center rounded-full border border-white/10 bg-white/[0.03] text-cyan-300 shadow-[0_0_30px_-8px_var(--color-signal-1)]">
        <flux:icon :name="$icon" variant="mini" />
    </div>
    <div class="max-w-md text-sm text-zinc-400">{{ $slot }}</div>
</div>

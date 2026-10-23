{{-- The RingMaster mark: an orbit ring around a core, in the signal gradient. --}}
<svg {{ $attributes }} viewBox="0 0 40 40" fill="none" aria-hidden="true">
    <defs>
        <linearGradient id="rm-mark-gradient" x1="4" y1="4" x2="36" y2="36" gradientUnits="userSpaceOnUse">
            <stop stop-color="#22d3ee" />
            <stop offset=".55" stop-color="#818cf8" />
            <stop offset="1" stop-color="#e879f9" />
        </linearGradient>
    </defs>
    <circle cx="20" cy="20" r="17" stroke="url(#rm-mark-gradient)" stroke-width="1.5" stroke-dasharray="4 3" opacity=".7" />
    <ellipse cx="20" cy="20" rx="17" ry="6.5" transform="rotate(-28 20 20)" stroke="url(#rm-mark-gradient)" stroke-width="1.5" />
    <circle cx="20" cy="20" r="5.5" fill="url(#rm-mark-gradient)" />
    <circle cx="33.4" cy="12.6" r="2" fill="#e879f9" />
</svg>

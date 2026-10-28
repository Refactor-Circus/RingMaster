<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) ? $title.' · ' : '' }}RingMaster</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@300..700&family=Geist+Mono:wght@400..600&display=swap">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen font-sans text-zinc-300 antialiased">
        <div class="rm-backdrop" aria-hidden="true"></div>

        <div class="flex min-h-screen">
            <aside class="sticky top-0 hidden h-screen w-60 shrink-0 flex-col gap-6 border-r border-white/5 bg-black/20 px-4 py-6 backdrop-blur-xl lg:flex">
                <a href="{{ route('board') }}" wire:navigate class="flex items-center gap-3 px-1">
                    <x-rm.mark class="size-9" />
                    <div class="leading-tight">
                        <div class="text-[15px] font-semibold tracking-tight text-white">RingMaster</div>
                        <div class="rm-kicker" style="font-size:.6rem">agent operations</div>
                    </div>
                </a>

                <nav class="flex flex-col gap-1">
                    <x-rm.nav-link :href="route('briefing')" icon="sun" :current="request()->routeIs('briefing', 'briefing.show')">Briefing</x-rm.nav-link>
                    <x-rm.nav-link :href="route('board')" icon="view-columns" :current="request()->routeIs('board', 'specs.show')">Board</x-rm.nav-link>
                    <x-rm.nav-link :href="route('inbox')" icon="inbox" :current="request()->routeIs('inbox')" :badge="$waitingOnYou ?: null" count="inbox" tone="wait">Inbox</x-rm.nav-link>
                    <x-rm.nav-link :href="route('tasks')" icon="queue-list" :current="request()->routeIs('tasks', 'tasks.show')" :badge="$queued ?: null" count="queued" tone="live">Tasks</x-rm.nav-link>
                    <x-rm.nav-link :href="route('runs')" icon="cpu-chip" :current="request()->routeIs('runs', 'runs.show')">Runs</x-rm.nav-link>
                    <x-rm.nav-link :href="route('campaigns')" icon="flag" :current="request()->routeIs('campaigns', 'campaigns.show')">Campaigns</x-rm.nav-link>
                    <x-rm.nav-link :href="route('workflow')" icon="arrow-path-rounded-square" :current="request()->routeIs('workflow')">Workflow</x-rm.nav-link>
                    <x-rm.nav-link :href="route('factory')" icon="beaker" :current="request()->routeIs('factory')">Factory</x-rm.nav-link>
                    <x-rm.nav-link :href="route('workspaces')" icon="folder" :current="request()->routeIs('workspaces')">Workspaces</x-rm.nav-link>
                </nav>

                <div class="mt-auto space-y-2">
                    <a href="{{ route('runs') }}" wire:navigate class="rm-panel rm-panel-hover rm-tone-{{ $runner }} flex items-center gap-3 px-3 py-3">
                        <span @class(['rm-orb', 'is-live' => $runner === 'live'])></span>
                        <div class="leading-tight">
                            <div class="text-xs font-medium text-white">Runner</div>
                            <div class="rm-mono text-[11px] text-zinc-400">{{ ['live' => 'online', 'wait' => 'paused', 'idle' => 'offline'][$runner] }}</div>
                        </div>
                    </a>
                    <x-rm.nav-link :href="route('settings')" icon="cog-6-tooth" :current="request()->routeIs('settings')">Settings</x-rm.nav-link>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-30 border-b border-white/5 bg-[#05060b]/60 backdrop-blur-xl">
                    <div class="mx-auto flex max-w-[1600px] items-center gap-3 px-4 py-3 sm:px-8">
                        <a href="{{ route('board') }}" wire:navigate class="lg:hidden"><x-rm.mark class="size-8" /></a>

                        <button type="button" x-data x-on:click="$dispatch('open-command')" class="rm-panel group flex max-w-2xl flex-1 items-center gap-3 px-4 py-2.5 text-left transition hover:border-white/15">
                            <flux:icon name="sparkles" variant="mini" class="size-4 text-cyan-300 drop-shadow-[0_0_6px_var(--color-signal-1)]" />
                            <span class="flex-1 truncate text-sm text-zinc-500 group-hover:text-zinc-300">Ask RingMaster to build something, or search…</span>
                            <kbd class="rm-mono hidden rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-[10px] text-zinc-400 sm:inline">⌘K</kbd>
                        </button>

                        <a href="{{ route('runs') }}" wire:navigate class="ml-auto hidden items-center gap-2 sm:flex" title="Runner {{ ['live' => 'online', 'wait' => 'paused', 'idle' => 'offline'][$runner] }}">
                            <span @class(['rm-dot', 'rm-tone-'.$runner, 'rm-dot-ping' => $runner === 'live'])></span>
                            <span class="rm-kicker">{{ ['live' => 'live', 'wait' => 'paused', 'idle' => 'offline'][$runner] }}</span>
                        </a>
                    </div>

                    <nav class="flex gap-1 overflow-x-auto px-3 pb-2 lg:hidden">
                        @foreach ([['briefing', 'Briefing', ['briefing', 'briefing.show']], ['board', 'Board', ['board', 'specs.show']], ['inbox', 'Inbox', ['inbox']], ['tasks', 'Tasks', ['tasks', 'tasks.show']], ['runs', 'Runs', ['runs', 'runs.show']], ['campaigns', 'Campaigns', ['campaigns', 'campaigns.show']], ['factory', 'Factory', ['factory']], ['workspaces', 'Workspaces', ['workspaces']], ['settings', 'Settings', ['settings']]] as [$route, $label, $active])
                            <a href="{{ route($route) }}" wire:navigate @class(['rm-mono shrink-0 rounded-lg px-3 py-1.5 text-xs', 'bg-white/10 text-white' => request()->routeIs(...$active), 'text-zinc-400' => ! request()->routeIs(...$active)])>{{ $label }}@if ($route === 'inbox')<span x-data="{ n: {{ (int) $waitingOnYou }} }" x-on:ring-master-counts.window="n = $event.detail.inbox" x-show="n > 0" @unless ($waitingOnYou) style="display: none" @endunless> · <span x-text="n">{{ $waitingOnYou }}</span></span>@endif</a>
                        @endforeach
                    </nav>
                </header>

                <main class="mx-auto w-full max-w-[1600px] flex-1 px-4 py-8 sm:px-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <livewire:command-bar />
        <livewire:browser-notifier />
        <livewire:nav-counter />
        <x-rm.alarm />

        @fluxScripts
    </body>
</html>

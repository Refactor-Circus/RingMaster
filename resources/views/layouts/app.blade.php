<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ isset($title) ? $title.' · ' : '' }}RingMaster</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:brand :href="route('board')" name="RingMaster" wire:navigate />

            <flux:navbar class="-mb-px">
                <flux:navbar.item icon="view-columns" :href="route('board')" :current="request()->routeIs('board', 'specs.show')" wire:navigate>Board</flux:navbar.item>
                <flux:navbar.item icon="inbox" :href="route('inbox')" :current="request()->routeIs('inbox')" :badge="$waitingOnYou ?: null" badge-color="amber" wire:navigate>Inbox</flux:navbar.item>
                <flux:navbar.item icon="queue-list" :href="route('tasks')" :current="request()->routeIs('tasks', 'tasks.show')" wire:navigate>Tasks</flux:navbar.item>
                <flux:navbar.item icon="folder" :href="route('workspaces')" :current="request()->routeIs('workspaces')" wire:navigate>Workspaces</flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" size="sm">
                <flux:radio value="light" icon="sun" aria-label="Light" />
                <flux:radio value="dark" icon="moon" aria-label="Dark" />
                <flux:radio value="system" icon="computer-desktop" aria-label="System" />
            </flux:radio.group>
        </flux:header>

        <flux:main container>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>

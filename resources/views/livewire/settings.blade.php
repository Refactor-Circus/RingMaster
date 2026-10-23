<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Settings</flux:heading>
        <flux:text>RingMaster reads its settings from <code class="font-mono">.env</code> (see <code class="font-mono">config/ring-master.php</code>); restart the server and the runner after changing them.</flux:text>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">Notifications</flux:heading>
        <flux:text>New questions and escalations, tasks that failed, and answers a run could not apply.</flux:text>

        <div class="grid gap-2 text-sm sm:grid-cols-[10rem_1fr]">
            <span class="text-zinc-500">Channels</span>
            <span>{{ $channels === [] ? 'none (RING_MASTER_NOTIFY)' : implode(', ', $channels) }}</span>
            <span class="text-zinc-500">Mail to</span>
            <span>{{ $mailTo ?? '—' }}</span>
            <span class="text-zinc-500">Webhook</span>
            <span>{{ $webhook ?? '—' }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button wire:click="test" size="sm" icon="bell">Send a test notification</flux:button>
            @if ($sent)
                <flux:text size="sm">{{ $sent }}</flux:text>
            @endif
        </div>

        <div x-data="{
                state: 'Notification' in window ? Notification.permission : 'unsupported',
                on: (() => { try { return localStorage.getItem('ring-master.notifications') === 'on' } catch (e) { return false } })(),
                async enable() {
                    if (this.state === 'unsupported') return;
                    this.state = await Notification.requestPermission();
                    this.on = this.state === 'granted';
                    try { localStorage.setItem('ring-master.notifications', this.on ? 'on' : 'off') } catch (e) {}
                },
                disable() { this.on = false; try { localStorage.setItem('ring-master.notifications', 'off') } catch (e) {} },
            }" class="flex flex-wrap items-center gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
            <flux:text size="sm" class="font-medium">Browser notifications in this tab</flux:text>
            <template x-if="state === 'unsupported'"><flux:text size="sm">not supported by this browser</flux:text></template>
            <template x-if="state !== 'unsupported' && ! on"><flux:button x-on:click="enable()" size="sm">Enable</flux:button></template>
            <template x-if="on"><flux:button x-on:click="disable()" size="sm" variant="ghost">Turn off</flux:button></template>
            <template x-if="state === 'denied'"><flux:text size="sm">blocked: allow notifications for this site in the browser</flux:text></template>
        </div>
    </flux:card>

    <flux:card class="space-y-3">
        <flux:heading size="lg">Runner and claims</flux:heading>
        <div class="grid gap-2 text-sm sm:grid-cols-[14rem_1fr]">
            <span class="text-zinc-500">Concurrency</span><span>{{ $runner['concurrency'] }}</span>
            <span class="text-zinc-500">Model</span><span>{{ $runner['model'] ?: 'session default' }}</span>
            <span class="text-zinc-500">Budget per session</span><span>{{ $runner['max_budget_usd'] ? '$'.$runner['max_budget_usd'] : 'no cap' }}</span>
            <span class="text-zinc-500">Attempts per task</span><span>{{ $runner['max_attempts'] }}</span>
            <span class="text-zinc-500">Claim lease</span><span>{{ $claims['lease_seconds'] }} s</span>
            <span class="text-zinc-500">Building counts as stalled after</span><span>{{ $claims['building_stall_minutes'] }} min without file changes</span>
            <span class="text-zinc-500">Allowed tools</span><span class="font-mono text-xs">{{ implode(' · ', $runner['allowed_tools']) }}</span>
            <span class="text-zinc-500">Workspaces from .env</span><span class="font-mono text-xs">{{ implode(', ', $workspaces) ?: '—' }}</span>
        </div>
    </flux:card>
</div>

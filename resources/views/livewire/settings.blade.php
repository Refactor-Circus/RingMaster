<div class="space-y-8">
    <x-rm.header kicker="configuration" title="Settings">
        <x-slot:lead>RingMaster reads its settings from .env (see config/ring-master.php). Restart the runner after changing them.</x-slot:lead>
    </x-rm.header>

    <section class="rm-panel space-y-6 p-6">
        <div>
            <h2 class="text-lg font-medium text-white">Notifications</h2>
            <p class="text-sm text-zinc-400">New questions and escalations, tasks that failed, and answers a run could not apply.</p>
        </div>

        <dl class="rm-mono grid gap-3 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-zinc-500">channels</dt><dd class="text-zinc-200">{{ $channels === [] ? 'none (RING_MASTER_NOTIFY)' : implode(', ', $channels) }}</dd>
            <dt class="text-zinc-500">mail to</dt><dd class="text-zinc-200">{{ $mailTo ?? '—' }}</dd>
            <dt class="text-zinc-500">webhook</dt><dd class="text-zinc-200">{{ $webhook ?? '—' }}</dd>
        </dl>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button wire:click="test" size="sm" icon="bell">Send a test notification</flux:button>
            @if ($sent)
                <span class="rm-mono text-xs text-cyan-200">{{ $sent }}</span>
            @endif
        </div>

        <div x-data="{
                // Browsers only ask for notification permission on https (or localhost).
                state: ! window.isSecureContext ? 'insecure' : ('Notification' in window ? Notification.permission : 'unsupported'),
                on: (() => { try { return localStorage.getItem('ring-master.notifications') === 'on' } catch (e) { return false } })(),
                async enable() {
                    if (this.state === 'unsupported' || this.state === 'insecure') return;
                    this.state = await Notification.requestPermission();
                    this.on = this.state === 'granted';
                    try { localStorage.setItem('ring-master.notifications', this.on ? 'on' : 'off') } catch (e) {}
                },
                disable() { this.on = false; try { localStorage.setItem('ring-master.notifications', 'off') } catch (e) {} },
            }" class="flex flex-wrap items-center gap-3 border-t border-white/5 pt-5">
            <span class="text-sm text-zinc-200">Browser notifications in this tab</span>
            <template x-if="state === 'unsupported'"><span class="rm-mono text-xs text-zinc-500">not supported by this browser</span></template>
            <template x-if="state === 'insecure'"><span class="rm-mono text-xs text-amber-300">needs https: browsers only ask for notification permission on a secure page. Run <code>herd secure ringmaster</code>, then open https://ringmaster.test</span></template>
            <template x-if="! ['unsupported', 'insecure'].includes(state) && ! on"><flux:button x-on:click="enable()" size="sm">Enable</flux:button></template>
            <template x-if="on"><span class="flex items-center gap-3"><x-rm.pill tone="done" dot>on</x-rm.pill><flux:button x-on:click="disable()" size="sm" variant="ghost">Turn off</flux:button></span></template>
            <template x-if="state === 'denied'"><span class="rm-mono text-xs text-amber-300">blocked: allow notifications for this site in the browser</span></template>
        </div>

        <div x-data="{
                s: window.RingMasterAlarm ? window.RingMasterAlarm.settings() : {},
                sounds: window.RingMasterAlarm ? window.RingMasterAlarm.SOUNDS : [],
                silent: false,
                save() { window.RingMasterAlarm?.save(this.s) },
                async test() { this.silent = ! (await window.RingMasterAlarm?.play(this.s.sound, Number(this.s.volume))) },
            }" x-init="$watch('s', () => save(), { deep: true })" class="space-y-4 border-t border-white/5 pt-5" data-alarm-settings>
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm text-zinc-200">Sound alarm when something waits in the Inbox</span>
                <label class="ml-auto flex items-center gap-2 text-sm text-zinc-300">
                    <input type="checkbox" x-model="s.on" class="rounded border-white/20 bg-black/30">on in this browser
                </label>
            </div>

            <div x-show="s.on" class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1">
                    <span class="rm-kicker">sound</span>
                    <select x-model="s.sound" class="w-full rounded-xl border border-white/10 bg-black/30 p-2 text-sm text-zinc-100">
                        <template x-for="name in sounds" :key="name"><option :value="name" x-text="name" :selected="name === s.sound"></option></template>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="rm-kicker">volume <span x-text="Math.round(s.volume * 100) + '%'"></span></span>
                    <input type="range" min="0.05" max="1" step="0.05" x-model.number="s.volume" class="w-full">
                </label>
                <label class="space-y-1">
                    <span class="rm-kicker">when</span>
                    <select x-model="s.mode" class="w-full rounded-xl border border-white/10 bg-black/30 p-2 text-sm text-zinc-100">
                        <option value="new">once, when something new arrives</option>
                        <option value="repeat">and again while anything waits</option>
                    </select>
                </label>
                <label x-show="s.mode === 'repeat'" class="space-y-1">
                    <span class="rm-kicker">repeat every</span>
                    <select x-model.number="s.every" class="w-full rounded-xl border border-white/10 bg-black/30 p-2 text-sm text-zinc-100">
                        <template x-for="m in [1, 2, 5, 10, 15, 30]" :key="m"><option :value="m" x-text="m + ' min'" :selected="m === s.every"></option></template>
                    </select>
                </label>
                <div class="space-y-2 sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-zinc-300">
                        <input type="checkbox" x-model="s.quiet" class="rounded border-white/20 bg-black/30">Quiet hours
                    </label>
                    <div x-show="s.quiet" class="rm-mono flex items-center gap-2 text-xs text-zinc-400">
                        from <input type="time" x-model="s.quietFrom" class="rounded-lg border border-white/10 bg-black/30 p-1 text-zinc-100">
                        to <input type="time" x-model="s.quietTo" class="rounded-lg border border-white/10 bg-black/30 p-1 text-zinc-100">
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <flux:button x-on:click="test()" size="sm" icon="speaker-wave">Play it</flux:button>
                    <span x-show="silent" class="rm-mono text-xs text-amber-300">the browser blocked audio: click anywhere on the page once, then try again</span>
                    <span class="rm-mono text-xs text-zinc-500">Rings in an open RingMaster tab; one tab rings for all. Browsers need one click on the page before they play sound.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="rm-panel space-y-5 p-6">
        <h2 class="text-lg font-medium text-white">Runner and claims</h2>
        <dl class="rm-mono grid gap-3 text-sm sm:grid-cols-[16rem_1fr]">
            <dt class="text-zinc-500">concurrency</dt><dd class="text-zinc-200">{{ $runner['concurrency'] }}</dd>
            <dt class="text-zinc-500">model</dt><dd class="text-zinc-200">{{ $runner['model'] ?: 'session default' }}</dd>
            <dt class="text-zinc-500">budget per session</dt><dd class="text-zinc-200">{{ $runner['max_budget_usd'] ? '$'.$runner['max_budget_usd'] : 'no cap' }}</dd>
            <dt class="text-zinc-500">attempts per task</dt><dd class="text-zinc-200">{{ $runner['max_attempts'] }}</dd>
            <dt class="text-zinc-500">claim lease</dt><dd class="text-zinc-200">{{ $claims['lease_seconds'] }} s</dd>
            <dt class="text-zinc-500">building stalls after</dt><dd class="text-zinc-200">{{ $claims['building_stall_minutes'] }} min without file changes</dd>
            <dt class="text-zinc-500">workspaces from .env</dt><dd class="text-zinc-200">{{ implode(', ', $workspaces) ?: '—' }}</dd>
        </dl>
        <div>
            <div class="rm-kicker mb-2">allowed tools</div>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($runner['allowed_tools'] as $tool)
                    <span class="rm-mono rounded-md border border-white/10 bg-white/[0.03] px-2 py-1 text-[11px] text-zinc-300">{{ $tool }}</span>
                @endforeach
            </div>
        </div>
    </section>
</div>

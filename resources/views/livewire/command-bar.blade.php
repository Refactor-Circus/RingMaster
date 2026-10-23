<div
    x-data="{
        open: false,
        active: 0,
        items() { return [...this.$refs.list?.querySelectorAll('[data-cmd]') ?? []] },
        show() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.input.focus()) },
        move(step) { const n = this.items().length; if (n) this.active = (this.active + step + n) % n },
        pick() { this.items()[this.active]?.click() },
    }"
    x-on:open-command.window="show()"
    x-on:keydown.window.prevent.meta.k="show()"
    x-on:keydown.window.prevent.ctrl.k="show()"
    x-on:keydown.window.slash="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) { $event.preventDefault(); show() }"
    x-on:keydown.escape.window="open = false"
>
    @teleport('body')
        <div x-show="open" x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-start justify-center bg-black/60 px-4 pt-[12vh] backdrop-blur-sm" x-on:click.self="open = false" style="display: none">
            <div class="rm-panel rm-signal-edge w-full max-w-2xl overflow-hidden" x-on:click.outside="open = false" x-transition.scale.98.duration.150ms>
                <div class="flex items-center gap-3 border-b border-white/5 px-5 py-4">
                    <flux:icon name="sparkles" variant="mini" class="size-5 text-cyan-300 drop-shadow-[0_0_8px_var(--color-signal-1)]" />
                    <input x-ref="input" wire:model.live.debounce.200ms="query" x-on:input="active = 0" x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="pick()"
                        class="w-full bg-transparent text-base text-white placeholder:text-zinc-500 focus:outline-none" placeholder="Describe what to build, or search specs, tasks and pages…" autocomplete="off" spellcheck="false">
                    <kbd class="rm-mono rounded-md border border-white/10 bg-white/5 px-1.5 py-0.5 text-[10px] text-zinc-400">esc</kbd>
                </div>

                <div x-ref="list" class="max-h-[55vh] overflow-y-auto p-2">
                    @if (trim($query) !== '')
                        <button type="button" data-cmd wire:click="queue" x-bind:class="items()[active] === $el && 'bg-white/[0.06]'" class="flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left">
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-cyan-400/30 via-indigo-400/30 to-fuchsia-400/30 text-white"><flux:icon name="rocket-launch" variant="micro" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-white">Queue as an idea</span>
                                <span class="block truncate text-xs text-zinc-400">“{{ Str::limit($query, 90) }}”</span>
                            </span>
                            <span class="rm-kicker shrink-0 self-center">enter</span>
                        </button>
                        <div class="flex items-center gap-2 px-3 pb-2" x-on:click.stop>
                            <span class="rm-kicker">into</span>
                            <select wire:model="projectId" class="rm-select rm-mono rounded-lg border border-white/10 bg-black/40 py-1 pl-2.5 text-xs text-zinc-200 focus:border-cyan-400/60 focus:outline-none">
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}">{{ $project->folder }}{{ $project->is_workspace ? ' (workspace)' : '' }}</option>
                                @endforeach
                            </select>
                            @error('query') <span class="text-xs text-rose-300">{{ $message }}</span> @enderror
                            @error('projectId') <span class="text-xs text-rose-300">Add a workspace first.</span> @enderror
                        </div>
                    @endif

                    @foreach ([['Specs', $specs], ['Tasks', $tasks]] as [$heading, $results])
                        @if ($results->isNotEmpty())
                            <div class="rm-kicker px-3 pb-1 pt-3">{{ $heading }}</div>
                            @foreach ($results as $result)
                                @php($isSpec = $result instanceof \App\Models\Spec)
                                <a data-cmd href="{{ $isSpec ? route('specs.show', $result) : route('tasks.show', $result) }}" wire:navigate x-on:click="open = false" x-bind:class="items()[active] === $el && 'bg-white/[0.06]'" class="flex items-center gap-3 rounded-xl px-3 py-2.5">
                                    <span class="rm-dot rm-tone-{{ $isSpec ? $result->tone() : $result->status->tone() }}"></span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-zinc-200">{{ $result->title }}</span>
                                    <span class="rm-mono shrink-0 text-xs text-zinc-500">{{ $result->project->folder }} · {{ $isSpec ? $result->status : $result->status->label() }}</span>
                                </a>
                            @endforeach
                        @endif
                    @endforeach

                    @if ($pages->isNotEmpty())
                        <div class="rm-kicker px-3 pb-1 pt-3">Go to</div>
                        @foreach ($pages as [$label, $route, $icon])
                            <a data-cmd href="{{ route($route) }}" wire:navigate x-on:click="open = false" x-bind:class="items()[active] === $el && 'bg-white/[0.06]'" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-zinc-300">
                                <flux:icon :name="$icon" variant="micro" class="text-zinc-500" />{{ $label }}
                            </a>
                        @endforeach
                    @endif
                </div>

                <div class="flex items-center gap-4 border-t border-white/5 px-5 py-2.5">
                    <span class="rm-kicker">↑↓ move</span><span class="rm-kicker">enter select</span>
                    <span class="rm-kicker ml-auto">claude picks up queued ideas</span>
                </div>
            </div>
        </div>
    @endteleport
</div>

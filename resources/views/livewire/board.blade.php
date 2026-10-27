<div wire:poll.3s="refresh" class="space-y-8">
    <x-rm.header kicker="mission control" title="Board" core>
        <x-slot:lead>Every job across your workspaces, from first idea to merged PR. Agents write as they work, so this moves on its own.</x-slot:lead>
    </x-rm.header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        <x-rm.stat label="queued" :value="$stats['queued']" tone="idle" :href="route('tasks')" />
        <x-rm.stat label="building" :value="$stats['building']" tone="live" :live="$stats['building'] > 0" />
        <x-rm.stat label="needs you" :value="$stats['needs']" tone="wait" :live="$stats['needs'] > 0" :href="route('inbox')" />
        <x-rm.stat label="in review" :value="$stats['review']" tone="review" />
        <x-rm.stat label="agents live" :value="$stats['live']" tone="live" :live="$stats['live'] > 0" :href="route('runs')" />
        <x-rm.stat label="spent 24h" :value="'$'.number_format($stats['spent'], 2)" tone="idle" :href="route('runs')" />
    </div>

    @if ($projects->isEmpty())
        <x-rm.empty icon="folder-plus">
            <span class="block text-base font-medium text-white">No projects yet</span>
            Add a workspace folder on the <a href="{{ route('workspaces') }}" wire:navigate class="text-cyan-300 underline underline-offset-4">Workspaces</a> page, or set RING_MASTER_WORKSPACES in .env.
        </x-rm.empty>
    @endif

    <div class="grid gap-6 2xl:grid-cols-[1fr_22rem]">
        <div class="min-w-0">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="rm-pipe min-w-24 flex-1"></div>
                <div class="relative">
                    <flux:icon name="magnifying-glass" variant="micro" class="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-zinc-500" />
                    <input wire:model.live.debounce.300ms="search" placeholder="Filter jobs" class="rm-panel h-10 w-52 rounded-xl py-0 pl-9 pr-3 text-sm text-white placeholder:text-zinc-500 focus:border-cyan-400/50 focus:outline-none">
                </div>
                <select wire:model.live="project" class="rm-panel rm-select rm-mono h-10 rounded-xl px-3 py-0 text-xs text-zinc-200 focus:border-cyan-400/50 focus:outline-none">
                    <option value="">All projects</option>
                    @foreach ($projects as $option)
                        <option value="{{ $option->id }}">{{ $option->folder }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-4 overflow-x-auto pb-4">
                <section class="flex w-72 shrink-0 flex-col gap-3" data-column="Queue">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2"><span class="rm-dot rm-tone-idle"></span><h2 class="rm-kicker text-zinc-300">Queue</h2></div>
                        <span class="rm-mono text-xs text-zinc-500">{{ $queue->count() }}</span>
                    </div>

                    @forelse ($queue as $task)
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="task-{{ $task->id }}" class="rm-panel rm-panel-hover rm-edge rm-tone-{{ $task->statusTone() }} rm-rise block space-y-3 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-medium leading-snug text-white">{{ $task->title }}</h3>
                                @if ($task->priority->value >= 2)
                                    <x-rm.pill :tone="$task->priority->value === 3 ? 'fail' : 'wait'">{{ strtolower($task->priority->label()) }}</x-rm.pill>
                                @endif
                            </div>
                            <div class="rm-mono flex items-center gap-2 text-[11px] text-zinc-500">
                                <span>{{ $task->project->folder }}</span><span>·</span><span>{{ strtolower($task->kind->label()) }}</span>
                                @if ($task->ticket_ref)<span>·</span><span>{{ $task->ticket_ref }}</span>@endif
                            </div>
                            <x-rm.pill :tone="$task->statusTone()" dot :ping="$task->statusTone() === 'live'">{{ $task->statusLabel() }}</x-rm.pill>
                        </a>
                    @empty
                        <button type="button" x-data x-on:click="$dispatch('open-command')" class="rounded-xl border border-dashed border-white/10 p-5 text-center text-sm text-zinc-500 transition hover:border-cyan-400/40 hover:text-cyan-200">
                            <flux:icon name="sparkles" variant="mini" class="mx-auto mb-1 size-4" />
                            Queue a task
                        </button>
                    @endforelse
                </section>

                @foreach ($columns as $column)
                    @php($cards = $specs->get($column, collect()))
                    @php($tone = ['Building' => 'live', 'Needs you' => 'wait', 'In review' => 'review', 'Done' => 'done'][$column] ?? 'idle')

                    @if ($cards->isEmpty())
                        <section class="flex h-52 w-10 shrink-0 flex-col items-center gap-3 self-start rounded-xl border border-dashed border-white/5 py-3" data-column="{{ $column }}" title="{{ $column }}: nothing here">
                            <span class="rm-dot rm-tone-{{ $tone }}" style="opacity:.6"></span>
                            <span class="rm-lane-folded rm-kicker text-zinc-500">{{ $column }}</span>
                            <span class="rm-mono text-[10px] text-zinc-600">0</span>
                        </section>
                        @continue
                    @endif

                    <section class="flex w-72 shrink-0 flex-col gap-3" data-column="{{ $column }}">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2"><span class="rm-dot rm-tone-{{ $tone }}"></span><h2 class="rm-kicker text-zinc-300">{{ $column }}</h2></div>
                            <span class="rm-mono text-xs text-zinc-500">{{ $cards->count() }}</span>
                        </div>

                        @forelse ($cards as $spec)
                            @php($reports = $spec->latestReports())
                            <a href="{{ route('specs.show', $spec) }}" wire:navigate wire:key="spec-{{ $spec->id }}" class="rm-panel rm-panel-hover rm-edge rm-tone-{{ $tone }} rm-rise block space-y-3 p-4 {{ $column === 'Done' ? 'opacity-70 hover:opacity-100' : '' }}">
                                <div class="space-y-1">
                                    <h3 class="text-sm font-medium leading-snug text-white">{{ $spec->title }}</h3>
                                    <div class="rm-mono flex flex-wrap items-center gap-x-2 text-[11px] text-zinc-500">
                                        <span>{{ $spec->project->folder }}</span>
                                        @if ($spec->ticketKey())<span>·</span><span class="text-zinc-400">{{ $spec->ticketKey() }}</span>@endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1.5">
                                    <x-rm.pill :tone="$tone" dot :ping="$tone === 'live'">{{ $spec->status }}</x-rm.pill>
                                    @if ($spec->questions > 0)
                                        <x-rm.pill tone="wait" dot ping>{{ $spec->questions }} {{ Str::plural('question', $spec->questions) }}</x-rm.pill>
                                    @endif
                                    @foreach ($spec->packages as $package)
                                        <x-rm.pill tone="review">{{ $package }}</x-rm.pill>
                                    @endforeach
                                </div>

                                @if ($spec->tasks_total > 0)
                                    <div class="space-y-1.5">
                                        <div class="rm-mono flex justify-between text-[10px] text-zinc-500"><span>Tasks {{ $spec->tasks_done }}/{{ $spec->tasks_total }}</span><span>{{ (int) round($spec->tasks_done / $spec->tasks_total * 100) }}%</span></div>
                                        <div @class(['rm-track', 'is-live' => $tone === 'live'])><span style="width: {{ round($spec->tasks_done / $spec->tasks_total * 100) }}%"></span></div>
                                    </div>
                                @endif

                                @if ($reports)
                                    <div class="flex items-center gap-2">
                                        <span class="rm-kicker" style="font-size:.6rem">round {{ count($spec->rounds) }}</span>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($reports as $report)
                                                <span title="{{ $report['name'] }}: {{ $report['verdict'] ?? 'running' }}" class="rm-mono rm-tone-{{ match ($report['verdict']) { 'pass' => 'done', 'changes' => 'fail', default => 'live' } }} inline-flex items-center gap-1 rounded-md bg-white/[0.03] px-1.5 py-0.5 text-[10px] text-zinc-400"><span class="rm-dot" style="width:.3rem;height:.3rem"></span>{{ $report['name'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($spec->spec_updated_at)
                                    <div class="rm-mono text-[10px] text-zinc-600">{{ $spec->spec_updated_at->diffForHumans() }}</div>
                                @endif
                            </a>
                        @empty
                            <div class="rounded-xl border border-dashed border-white/5 p-5 text-center text-xs text-zinc-600">Nothing here</div>
                        @endforelse
                    </section>
                @endforeach
            </div>
        </div>

        <div class="hidden 2xl:block">
            <div class="sticky top-24">
                <livewire:activity-feed />
            </div>
        </div>
    </div>
</div>

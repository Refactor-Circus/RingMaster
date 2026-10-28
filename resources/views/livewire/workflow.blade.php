<div wire:poll.10s class="space-y-8">
    <x-rm.header kicker="how jobs move" title="Workflow">
        <x-slot:lead>The stages a job goes through in this project, from idea to merged PR, as its ring-master.yml sets them. Highlighted values are set there; the rest are Ringside's defaults.</x-slot:lead>
        <x-slot:actions>
            @if ($projects->isNotEmpty())
                <select wire:model.live="project" aria-label="Project" class="rm-panel rm-select rm-mono h-10 rounded-xl px-3 py-0 text-xs text-zinc-200 focus:border-cyan-400/50 focus:outline-none">
                    @foreach ($projects as $option)
                        <option value="{{ $option->id }}" @selected($current?->id === $option->id)>{{ $option->folder }}{{ $option->is_workspace ? ' (workspace)' : '' }}</option>
                    @endforeach
                </select>
            @endif
        </x-slot:actions>
    </x-rm.header>

    @if ($current === null)
        <x-rm.empty icon="folder-plus">No projects yet. Add a workspace on the <a href="{{ route('workspaces') }}" wire:navigate class="text-cyan-300 underline underline-offset-4">Workspaces</a> page.</x-rm.empty>
    @elseif ($map === null)
        <div class="rm-panel rm-tone-fail border-[color:var(--rm-tone)] p-5 text-sm text-red-200">{{ $error }}</div>
    @else
        <div class="rm-mono flex flex-wrap items-center gap-x-5 gap-y-2 text-[11px] text-zinc-500">
            <span>{{ $current->path }}/ring-master.yml</span>
            @if (! $map->configured())
                <x-rm.pill tone="idle">not found: all defaults</x-rm.pill>
            @endif
            <span class="flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-cyan-300"></span>set in ring-master.yml</span>
            <span class="flex items-center gap-1.5"><span class="rm-dot rm-tone-live" style="width:.4rem;height:.4rem"></span>jobs there now</span>
        </div>

        {{-- Ship stages --}}
        <section aria-label="Stages">
            <div class="mb-3 flex items-center gap-2 px-1"><span class="rm-dot rm-tone-live"></span><h2 class="rm-kicker text-zinc-300">Stages</h2><span class="rm-mono text-[11px] text-zinc-500">run by /ring-master-ship, in order</span></div>
            <ol class="flex items-stretch overflow-x-auto pb-3">
                @foreach ($map->stages() as $i => $stage)
                    @php($here = array_sum(array_map(fn ($s) => $counts[$s] ?? 0, $stage['statuses'])))
                    <li wire:key="stage-{{ $stage['key'] }}" class="flex items-stretch" data-stage="{{ $stage['key'] }}">
                        <div @class([
                            'rm-panel flex w-60 shrink-0 flex-col gap-3 p-4',
                            'rm-tone-live border-[color:var(--rm-tone)] shadow-[0_0_24px_-10px_var(--rm-tone)]' => $stage['on'] && $here > 0,
                            'border-dashed opacity-45' => ! $stage['on'],
                        ])>
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="rm-mono text-[10px] text-zinc-500">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</div>
                                    <h3 class="text-sm font-semibold text-white">{{ $stage['name'] }}</h3>
                                </div>
                                @if (! $stage['on'])
                                    <x-rm.pill tone="idle">off</x-rm.pill>
                                @elseif ($here > 0)
                                    <x-rm.pill tone="live" dot ping>{{ $here }}</x-rm.pill>
                                @elseif ($stage['mode'])
                                    <x-rm.pill tone="review">{{ $stage['mode'] }}</x-rm.pill>
                                @endif
                            </div>
                            <p class="text-xs leading-relaxed text-zinc-400">{{ $stage['does'] }}</p>
                            @if ($stage['skill'])
                                <div class="rm-mono text-[11px] text-cyan-300/80 [overflow-wrap:anywhere]" title="{{ $stage['skill'] }}">{{ $stage['skill'] }}</div>
                            @endif
                            <dl class="space-y-1.5">
                                @foreach ($stage['facts'] as $f)
                                    <div>
                                        <dt class="rm-mono text-[10px] uppercase tracking-wider text-zinc-500">{{ $f['label'] }}</dt>
                                        <dd @class(['flex min-w-0 items-start gap-1.5 text-xs', 'text-cyan-200' => $f['set'], 'text-zinc-300' => ! $f['set']])>@if ($f['set'])<span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-cyan-300" title="set in ring-master.yml"></span>@endif<span class="min-w-0 [overflow-wrap:anywhere]">{{ $f['value'] }}</span></dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                        @if (! $loop->last)
                            <div class="flex w-6 shrink-0 items-center" aria-hidden="true"><div class="rm-pipe w-full"></div></div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        {{-- Factory --}}
        @php($factory = $map->factory())
        <section aria-label="Factory" class="rm-panel space-y-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2"><span class="rm-dot rm-tone-live"></span><h2 class="rm-kicker text-zinc-300">Inside the factory</h2><span class="rm-mono text-[11px] text-zinc-500">one agent per step, in the job's worktree</span></div>
                @if ($here = ($counts['building'] ?? 0) + ($counts['approved:tasked'] ?? 0))
                    <x-rm.pill tone="live" dot ping>{{ $here }} building</x-rm.pill>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($factory['once'] as $step)
                    @include('livewire.partials.workflow-step', ['step' => $step, 'n' => $loop->iteration])
                @endforeach
            </div>

            <div class="min-w-0 space-y-3 rounded-2xl border border-dashed border-cyan-400/25 p-4" data-loop>
                <div class="rm-mono flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] text-cyan-300/80">
                    <span class="uppercase tracking-wider">↺ then in review rounds</span>
                    @foreach ($factory['rounds'] as $f)
                        <span @class(['text-cyan-200' => $f['set'], 'text-zinc-400' => ! $f['set']])>{{ $f['label'] }} {{ $f['value'] }}</span>
                    @endforeach
                </div>
                <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
                    @foreach ($factory['loop'] as $step)
                        @include('livewire.partials.workflow-step', ['step' => $step, 'n' => count($factory['once']) + $loop->iteration])
                    @endforeach
                </div>
                <div class="rm-mono text-[10px] text-zinc-500">findings at or above the rework threshold → rework → back to the gate</div>
            </div>

            @if ($factory['after'] !== [])
                <div class="space-y-2" data-after>
                    <div class="rm-kicker text-zinc-400">then, once it would approve</div>
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($factory['after'] as $step)
                            @include('livewire.partials.workflow-step', ['step' => $step, 'n' => count($factory['once']) + count($factory['loop']) + $loop->iteration])
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <div class="rm-kicker mb-2 text-zinc-400">A run ends with</div>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach ($map->exits() as $exit)
                        @php($waiting = $counts[$exit['key']] ?? 0)
                        <div wire:key="exit-{{ $exit['key'] }}" class="rm-panel rm-edge rm-tone-{{ $exit['tone'] }} space-y-2 p-4" data-exit="{{ $exit['key'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-white">{{ $exit['name'] }}</h3>
                                @if ($waiting > 0)
                                    <x-rm.pill :tone="$exit['tone']" dot>{{ $waiting }}</x-rm.pill>
                                @endif
                            </div>
                            <ul class="space-y-1 text-xs text-zinc-400">
                                @foreach ($exit['when'] as $when)
                                    <li class="flex gap-2"><span class="text-zinc-600">·</span><span class="min-w-0 [overflow-wrap:anywhere]">{{ $when }}</span></li>
                                @endforeach
                            </ul>
                            @if ($exit['key'] === 'approved')
                                <div class="rm-mono text-[11px] text-zinc-500">→ PR review</div>
                            @else
                                <a href="{{ route('inbox') }}" wire:navigate class="rm-mono text-[11px] text-cyan-300 hover:underline">→ your Inbox: answer, approve or send back</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section aria-label="Last 7 days" class="space-y-3" data-metrics>
            <div class="flex items-center gap-2 px-1"><span class="rm-dot rm-tone-idle"></span><h2 class="rm-kicker text-zinc-300">Last 7 days</h2><span class="rm-mono text-[11px] text-zinc-500">{{ $current->is_workspace ? 'every package in this workspace' : $current->folder }}</span></div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['runs', $metrics['runs'], $metrics['running'].' running now'],
                    ['succeeded', $metrics['runs'] > 0 ? round(100 * $metrics['succeeded'] / $metrics['runs']).'%' : '—', $metrics['succeeded'].' of '.$metrics['runs']],
                    ['spent', '$'.number_format($metrics['cost'], 2), $metrics['jobs'] > 0 ? '$'.number_format($metrics['cost'] / $metrics['jobs'], 2).' per job' : 'no jobs'],
                    ['median run', $metrics['median'] === null ? '—' : \Carbon\CarbonInterval::seconds($metrics['median'])->cascade()->forHumans(short: true), 'start to finish'],
                    ['CI auto-fixes', $metrics['ci_fixes'], $metrics['refused'].' refused command(s)'],
                ] as [$label, $value, $sub])
                    <div class="rm-panel space-y-1 p-4">
                        <div class="rm-kicker">{{ $label }}</div>
                        <div class="text-2xl font-semibold text-white">{{ $value }}</div>
                        <div class="rm-mono text-[11px] text-zinc-500">{{ $sub }}</div>
                    </div>
                @endforeach
            </div>
            @if ($metrics['failures'] !== [])
                <div class="rm-mono flex flex-wrap gap-x-5 gap-y-1 px-1 text-[11px] text-zinc-400">
                    <span class="text-zinc-500">failed runs:</span>
                    @foreach ($metrics['failures'] as $label => $count)
                        <span>{{ $label }} ×{{ $count }}</span>
                    @endforeach
                </div>
            @endif
        </section>

        <p class="text-xs text-zinc-500">Any stage that hits a design question stops and asks you in the Inbox; your answer resumes the job where it stopped. A merged PR is noticed within five minutes and moves the job to its post-mortem.</p>
    @endif
</div>

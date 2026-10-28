<?php

namespace App\Support;

use RefactorCircus\Ringside\Config;

/**
 * The workflow a project's jobs go through, read from its ring-master.yml
 * merged over Ringside's defaults: the ship stages in order, the factory's
 * build and review loop, and the ways a factory run ends. Each fact says
 * whether ring-master.yml sets it, so the page can show what is local.
 *
 * @phpstan-type Fact array{label: string, value: string, set: bool}
 * @phpstan-type Stage array{key: string, name: string, skill: ?string, on: bool, mode: ?string, does: string, facts: list<Fact>, statuses: list<string>}
 * @phpstan-type Step array{key: string, name: string, role: ?string, on: bool, does: string, enable: ?string, facts: list<Fact>}
 * @phpstan-type Exit array{key: string, name: string, tone: string, when: list<string>}
 */
final readonly class WorkflowMap
{
    /** What each stage and factory step does, keyed as in stages() and factory(). */
    private const array DESCRIPTIONS = [
        'ticket' => 'Drafts a ticket from your idea (problem, outcome, acceptance hints, out of scope), has you confirm it, creates it in the tracker and opens the job\'s spec folder.',
        'shape' => 'Captures the scope, product context, similar code worth reusing and the standards that apply, asking you where anything is unclear. Writes shape.md and references.md.',
        'spec' => 'Turns the shape into spec.md with numbered acceptance criteria, and lists the standards that apply in standards.md.',
        'spec_review' => 'Independent critics pick the spec apart. It fixes what is not architectural, asks you the design questions, then asks you to approve the spec.',
        'tasks' => 'Breaks the approved spec into dependency-ordered task groups in tasks.md: each one agent\'s slice, with its files, criteria and tests first.',
        'factory' => 'Builds the spec in its own worktree and branch, then gates and reviews it in rounds. Ends by opening the PR, asking your design questions, or escalating.',
        'pr' => 'The PR waits for your review and merge. RingMaster checks it every five minutes: failing checks come to your Inbox, and a merge moves the job on.',
        'post_mortem' => 'An analyst writes post-mortem.md from the runs, findings and comments, and a discoverer proposes standards changes for you to accept or reject. Then the job closes and its worktree is removed.',
        'prepare' => 'Creates or reuses the job\'s worktree on its branch and installs its dependencies. Stops the run if that fails rather than repairing anything by hand.',
        'build' => 'One agent per task group writes the tests first, then the code, runs the tests until they pass and commits on the job branch. Finished groups stay ticked, so a resumed run carries on.',
        'gate' => 'Runs the verify and format checks and ringside check in the worktree, and maps every acceptance criterion to the test that proves it. A red gate skips the reviewers; its failures become blockers to fix.',
        'review' => 'Each reviewer reads the diff through its own lens and returns findings with severities, plus any design questions for you.',
        'triage' => 'Merges duplicate findings from the reviewers and may move one a step in severity, never to or from architectural. Runs only when there is more than one finding.',
        'integrate' => 'When groups of a wave were built in parallel, each in its own worktree, one agent merges their branches into the job branch, resolves the conflicts, runs the tests and commits.',
        'impact' => 'Before the PR, runs the tests of every package that uses this one against the job branch, in parallel. A package it breaks gets a proposed fix task that starts once this PR is merged.',
        'brief' => 'Once it would approve, writes brief.md for you: what changed, why, the few places to look at, which tests prove what, the risks, and a low, medium or high risk call. The PR starts with it and the briefing ranks by it.',
        'rework' => 'Fixes the findings at or above the rework threshold, runs the tests and commits, then the next round starts at the gate. Architectural findings are never fixed here: they come to you.',
    ];

    public function __construct(public Config $config) {}

    public static function load(string $path): self
    {
        return new self(Config::load($path));
    }

    public function configured(): bool
    {
        return trim($this->config->raw) !== '';
    }

    /**
     * The ship stages in order. `statuses` are the spec statuses of jobs
     * waiting at or working in the stage.
     *
     * @return list<Stage>
     */
    public function stages(): array
    {
        $postMortem = $this->config->get('stages.post_mortem');

        return [
            $this->stage('ticket', 'Ticket', 'ring-master-ticket', $this->config->bool('stages.ticket'), [
                $this->roleFact('ticketer'),
                $this->fact('tracker', 'tickets.default'),
            ], statuses: ['queued']),
            $this->stage('shape', 'Shape', 'ring-master-shape-spec', $this->config->bool('stages.shape'), [
                $this->roleFact('shaper'),
                ['label' => 'standards', 'value' => implode(', ', array_column($this->config->globalStandards(), 'package')) ?: 'local only', 'set' => $this->set('standards.global')],
            ], statuses: ['ticketed', 'shaping']),
            $this->stage('spec', 'Write spec', 'ring-master-write-spec', true, [
                $this->roleFact('writer'),
                ['label' => 'writes', 'value' => 'spec.md, standards.md', 'set' => false],
            ]),
            $this->stage('spec_review', 'Spec review', 'ring-master-spec-review', $this->config->bool('stages.spec_review.enabled'), [
                $this->fact('critics', 'stages.spec_review.critics'),
                $this->fact('rounds', 'stages.spec_review.rounds'),
                $this->fact('max passes', 'stages.spec_review.max_passes'),
                $this->roleFact('orchestrator'),
                $this->roleFact('critic', 'critics model'),
            ], statuses: ['written']),
            $this->stage('tasks', 'Tasks', 'ring-master-create-tasks', $this->config->bool('stages.tasks'), [
                $this->roleFact('planner'),
                ['label' => 'writes', 'value' => 'tasks.md, tests first', 'set' => false],
            ], statuses: ['approved:untasked']),
            $this->stage('factory', 'Factory', 'ring-master-factory', $this->config->bool('stages.factory'), [
                $this->roleFact('orchestrator'),
                ['label' => 'groups', 'value' => $this->config->bool('build.parallel') ? 'in parallel, up to '.$this->config->int('build.max_concurrency') : 'one at a time', 'set' => $this->set('build.parallel') || $this->set('build.max_concurrency')],
                $this->fact('rounds', 'review.rounds.max', fn (mixed $max): string => 'up to '.$this->scalar($max)),
            ], statuses: ['approved:tasked', 'building']),
            $this->stage('pr', 'PR review', null, true, [
                $this->fact('host', 'host.provider'),
                $this->fact('base', 'host.base'),
                $this->fact('draft', 'host.draft_pr'),
                ['label' => 'merge', 'value' => 'you, never automatic', 'set' => false],
            ], statuses: ['in-review']),
            $this->stage('post_mortem', 'Post-mortem', 'ring-master-post-mortem', $postMortem !== false && $postMortem !== 'off', [
                $this->roleFact('orchestrator'),
                $this->roleFact('analyst', 'analyst model'),
                $this->roleFact('discoverer', 'discoverer model'),
                $this->fact('discovery', 'discovery.enabled'),
            ], mode: is_string($postMortem) ? str_replace('_', ' ', $postMortem) : null, statuses: ['merged']),
        ];
    }

    /**
     * The factory's steps: prepare and build once, then gate, review and
     * rework in rounds.
     *
     * @return array{once: list<Step>, loop: list<Step>, after: list<Step>, rounds: list<Fact>}
     */
    public function factory(): array
    {
        $reviewers = array_map(
            fn (array $reviewer): string => $this->scalar($reviewer['name']).(isset($reviewer['model']) ? ' · '.$this->scalar($reviewer['model']) : ''),
            $this->config->reviewers(),
        );
        $commands = array_filter([
            $this->config->nullableString('commands.verify'),
            $this->config->nullableString('commands.format_check'),
            'ringside check',
        ]);

        return [
            'once' => [
                $this->step('prepare', 'Prepare worktree', 'prepare', [
                    $this->fact('mode', 'build.worktree.mode'),
                    $this->fact('path', 'build.worktree.path'),
                    ['label' => 'setup', 'value' => $this->config->worktreeSetup(), 'set' => $this->set('build.worktree.setup')],
                ]),
                $this->step('build', 'Build groups', 'implementer', [
                    $this->fact('max turns', 'roles.implementer.max_turns'),
                    $this->fact('tests', 'commands.test'),
                    ['label' => 'groups', 'value' => $this->config->bool('build.parallel') ? 'parallel, own worktree each, up to '.$this->config->int('build.max_concurrency') : 'one at a time', 'set' => $this->set('build.parallel')],
                    ['label' => 'variants', 'value' => $this->config->int('build.variants') > 1 ? $this->config->int('build.variants').' builds, judged by '.$this->roleFact('judge')['value'] : 'one build (a spec can ask for more; rebuild with '.$this->config->int('build.escalation_variants').' on escalation)', 'set' => $this->set('build.variants') || $this->set('build.escalation_variants')],
                ]),
                $this->step('integrate', 'Integrate', 'integrator', [], $this->config->bool('build.parallel') && $this->config->string('build.worktree.mode') === 'per-job', enable: 'build: { parallel: true }'),
            ],
            'loop' => [
                $this->step('gate', 'Gate', 'gate', [
                    ['label' => 'runs', 'value' => implode(' · ', $commands), 'set' => $this->set('commands.verify') || $this->set('commands.format_check')],
                    $this->fact('criteria mapping', 'review.gate.criteria_mapping'),
                    $this->fact('runs first', 'review.gate.run_first'),
                ]),
                $this->step('review', 'Reviewers', 'reviewer', [
                    ['label' => 'reviewers', 'value' => implode(', ', $reviewers) ?: 'none', 'set' => $this->set('review.reviewers')],
                ]),
                $this->step('triage', 'Triage', 'triage', [], (bool) ($this->config->role('triage')['enabled'] ?? true), enable: 'roles: { triage: { enabled: true } }'),
                $this->step('rework', 'Rework', 'reworker', [
                    $this->fact('fix from', 'review.rework_threshold'),
                    $this->fact('auto-fix', 'review.auto_fix'),
                ]),
            ],
            'after' => [
                $this->step('impact', 'Impact gate', 'impact', [
                    ['label' => 'consumers', 'value' => $this->scalar($this->config->get('impact.consumers')), 'set' => $this->set('impact.consumers')],
                ], $this->config->bool('impact.enabled') && $this->config->get('impact.consumers') !== 'off', enable: 'impact: { enabled: true }'),
                $this->step('brief', 'Reviewer brief', 'briefer', []),
            ],
            'rounds' => [
                $this->fact('max', 'review.rounds.max'),
                $this->fact('min', 'review.rounds.min'),
                $this->fact('stop after no progress', 'review.stop_when_no_progress', fn (mixed $n): string => (int) $n > 0 ? $this->scalar($n).' rounds' : 'never'),
            ],
        ];
    }

    /**
     * How a factory run ends.
     *
     * @return list<Exit>
     */
    public function exits(): array
    {
        $approver = (bool) ($this->config->role('approver')['enabled'] ?? false);
        $red = $this->config->int('review.gate.max_consecutive_red');
        $stall = $this->config->int('review.stop_when_no_progress');

        return [
            ['key' => 'approved', 'name' => 'PR opened', 'tone' => 'done', 'when' => array_values(array_filter([
                'gate green and nothing left above '.$this->config->string('review.rework_threshold'),
                $approver ? 'the approver agent signs off' : null,
                $this->config->bool('host.draft_pr') ? 'opened as a draft' : null,
            ]))],
            ['key' => 'needs-decision', 'name' => 'Needs you', 'tone' => 'wait', 'when' => [
                'an architectural finding or open design question',
                'a change to '.implode(', ', $this->config->list('escalation.architectural_paths')),
            ]],
            ['key' => 'escalated', 'name' => 'Escalated', 'tone' => 'fail', 'when' => array_values(array_filter([
                $red > 0 ? "gate red {$red} rounds in a row" : null,
                $stall > 0 ? "the same findings {$stall} rounds in a row" : null,
                'findings left after '.$this->config->int('review.rounds.max').' rounds',
            ]))],
        ];
    }

    /**
     * Whether ring-master.yml sets the key to something other than the default.
     */
    public function set(string $key): bool
    {
        $default = Config::defaults();

        foreach (explode('.', $key) as $segment) {
            $default = is_array($default) && array_key_exists($segment, $default) ? $default[$segment] : null;
        }

        return $this->config->get($key) !== $default;
    }

    /**
     * @param  list<Fact>  $facts
     * @param  list<string>  $statuses
     * @return Stage
     */
    private function stage(string $key, string $name, ?string $skill, bool $on, array $facts, ?string $mode = null, array $statuses = []): array
    {
        return ['key' => $key, 'name' => $name, 'skill' => $skill === null ? null : '/'.$skill, 'on' => $on, 'mode' => $mode, 'does' => self::DESCRIPTIONS[$key] ?? '', 'facts' => $facts, 'statuses' => $statuses];
    }

    /**
     * @param  list<Fact>  $facts
     * @return Step
     */
    private function step(string $key, string $name, ?string $role, array $facts, bool $on = true, ?string $enable = null): array
    {
        return ['key' => $key, 'name' => $name, 'role' => $role, 'on' => $on, 'does' => self::DESCRIPTIONS[$key] ?? '', 'enable' => $enable, 'facts' => $role === null ? $facts : [$this->roleFact($role), ...$facts]];
    }

    /**
     * @param  (callable(mixed): string)|null  $format
     * @return Fact
     */
    private function fact(string $label, string $key, ?callable $format = null): array
    {
        $value = $this->config->get($key);

        return ['label' => $label, 'value' => $format !== null ? $format($value) : $this->scalar($value), 'set' => $this->set($key)];
    }

    /**
     * @return Fact
     */
    private function roleFact(string $role, string $label = 'model'): array
    {
        $settings = $this->config->role($role);
        $value = implode(' · ', array_filter([
            isset($settings['model']) ? $this->scalar($settings['model']) : 'session model',
            isset($settings['effort']) ? $this->scalar($settings['effort']).' effort' : null,
        ]));

        if (is_string($settings['escalate_model'] ?? null) && $settings['escalate_model'] !== ($settings['model'] ?? null)) {
            $value .= ' → '.$settings['escalate_model'].' on retry';
        }

        return ['label' => $label, 'value' => $value, 'set' => $this->set('roles.'.$role.'.model') || $this->set('roles.'.$role.'.effort') || $this->set('roles.'.$role.'.escalate_model')];
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map($this->scalar(...), $value)),
            $value === null => '—',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}

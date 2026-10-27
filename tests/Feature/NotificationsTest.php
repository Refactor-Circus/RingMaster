<?php

use App\Actions\ScanWorkspaces;
use App\Livewire\BrowserNotifier;
use App\Livewire\Settings;
use App\Models\Activity;
use App\Notifications\NeedsYou;
use App\Support\Notifier;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    Process::fake();
    Http::fake();
});

it('sends nothing while no channel is configured', function () {
    config(['ring-master.notifications.channels' => []]);
    Notification::fake();

    app(Notifier::class)->send('Hello', ['line']);

    Notification::assertNothingSent();
});

it('posts {"text": ...} to the webhook and runs the desktop notifier with the text as arguments', function () {
    config(['ring-master.notifications' => ['channels' => ['webhook', 'desktop'], 'webhook_url' => 'https://hooks.example.test/x', 'mail_to' => null, 'app_url' => 'http://127.0.0.1:8000']]);

    app(Notifier::class)->send('2 things need you', ['Alpha: "quoted" question'], '/inbox');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/x'
        && str_contains($request['text'], '2 things need you')
        && str_contains($request['text'], 'http://127.0.0.1:8000/inbox'));

    if (in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
        Process::assertRan(fn ($process) => in_array('RingMaster: 2 things need you', (array) $process->command, true)
            && in_array('Alpha: "quoted" question', (array) $process->command, true));
    }
});

it('mails the configured address', function () {
    config(['ring-master.notifications' => ['channels' => ['mail'], 'mail_to' => 'me@example.test', 'webhook_url' => null, 'app_url' => 'http://x']]);
    Notification::fake();

    app(Notifier::class)->send('A task failed', ['Beta: Exports']);

    Notification::assertSentOnDemand(NeedsYou::class, fn (NeedsYou $notification, array $channels, AnonymousNotifiable $notifiable) => $channels === ['mail'] && $notifiable->routes['mail'] === 'me@example.test');
});

it('keeps sending through the other channels when one fails', function () {
    config(['ring-master.notifications' => ['channels' => ['webhook', 'desktop'], 'webhook_url' => 'https://hooks.example.test/x', 'mail_to' => null, 'app_url' => 'http://x']]);
    Http::fake(['*' => Http::response('nope', 500)]);

    app(Notifier::class)->send('Hello', ['line']);

    if (in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
        Process::assertRanTimes(fn () => true, 1);
    } else {
        expect(true)->toBeTrue();
    }
});

it('sends one notification for everything a scan raises', function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()], 'ring-master.notifications' => ['channels' => ['webhook'], 'webhook_url' => 'https://hooks.example.test/x', 'mail_to' => null, 'app_url' => 'http://x']]);

    app(ScanWorkspaces::class)->handle();
    app(ScanWorkspaces::class)->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['text'], '2 things need you')
        && str_contains($request['text'], 'Alpha: One row per file or per line?')
        && str_contains($request['text'], 'Alpha: Sync or queued?'));
});

it('tells an open tab about new questions and failed tasks, not old ones', function () {
    Activity::record('attention.raised', 'Question: old one');
    $notifier = Livewire::test(BrowserNotifier::class);

    $notifier->call('check')->assertNotDispatched('ring-master-notify');

    Activity::record('attention.raised', 'Question: Sync or queued?');
    $notifier->call('check')->assertDispatched('ring-master-notify', title: 'RingMaster: 1 thing needs you', body: 'Question: Sync or queued?', url: route('inbox'));

    Activity::record('task.failed', 'Failed after 2 attempt(s)');
    $notifier->call('check')->assertDispatched('ring-master-notify', title: 'RingMaster: a task failed');
});

it('shows the notification setup and sends a test', function () {
    config(['ring-master.notifications' => ['channels' => ['webhook'], 'webhook_url' => 'https://hooks.example.test/secret-token', 'mail_to' => null, 'app_url' => 'http://x']]);

    Livewire::test(Settings::class)
        ->assertSee('webhook')
        ->assertSee('hooks.example.test')
        ->assertDontSee('secret-token')
        ->call('test')
        ->assertSee('Sent through: webhook.');

    Http::assertSent(fn ($request) => str_contains($request['text'], 'Test notification'));
});

it('offers the inbox alarm in Settings and loads it on every page', function () {
    Livewire::test(Settings::class)->assertSee('Sound alarm when something waits in the Inbox')->assertSee('Quiet hours');

    $this->get(route('inbox'))->assertOk()->assertSee('window.RingMasterAlarm', false)->assertSee('ring-master-counts', false);
});

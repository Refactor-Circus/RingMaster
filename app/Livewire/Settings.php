<?php

namespace App\Livewire;

use App\Support\Notifier;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    public ?string $sent = null;

    public function test(Notifier $notifier): void
    {
        $notifier->send('Test notification', ['RingMaster can reach you here.'], '/settings');
        $this->sent = 'Sent through: '.(implode(', ', $this->channels()) ?: 'no channel (set RING_MASTER_NOTIFY)').'.';
    }

    /**
     * @return list<string>
     */
    private function channels(): array
    {
        /** @var list<string> $channels */
        $channels = config('ring-master.notifications.channels', []);

        return $channels;
    }

    public function render(): View
    {
        $webhook = (string) config('ring-master.notifications.webhook_url');

        return view('livewire.settings', [
            'channels' => $this->channels(),
            'mailTo' => config('ring-master.notifications.mail_to'),
            'webhook' => $webhook === '' ? null : (parse_url($webhook, PHP_URL_HOST) ?: 'set'),
            'runner' => config('ring-master.runner'),
            'claims' => config('ring-master.claims'),
            'workspaces' => config('ring-master.workspaces'),
        ]);
    }
}

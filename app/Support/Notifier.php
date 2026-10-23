<?php

namespace App\Support;

use App\Notifications\NeedsYou;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends NeedsYou through the configured channels. A failing channel is
 * logged and never breaks the scan or the runner that triggered it.
 */
final class Notifier
{
    /**
     * @param  list<string>  $lines
     */
    public function send(string $title, array $lines, string $path = '/inbox'): void
    {
        if (config('ring-master.notifications.channels', []) === []) {
            return;
        }

        $url = rtrim((string) config('ring-master.notifications.app_url'), '/').$path;

        $notification = new NeedsYou($title, $lines, $url);
        $to = Notification::route('mail', (string) config('ring-master.notifications.mail_to'));

        foreach ($notification->via($to) as $channel) {
            try {
                $to->notifyNow($notification->on($channel));
            } catch (Throwable $exception) {
                Log::warning("RingMaster notification through {$channel} failed: ".$exception->getMessage());
            }
        }
    }
}

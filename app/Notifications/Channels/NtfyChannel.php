<?php

namespace App\Notifications\Channels;

use App\Notifications\NeedsYou;
use Illuminate\Support\Facades\Http;

/**
 * A push to your phone through ntfy (ntfy.sh or your own server): the
 * notification's link opens RingMaster, and up to three single-use buttons
 * act straight from the notification by POSTing their signed link.
 */
class NtfyChannel
{
    public function send(object $notifiable, NeedsYou $notification): void
    {
        $url = (string) config('ring-master.notifications.ntfy_url');
        $parts = parse_url($url);
        $topic = trim((string) ($parts['path'] ?? ''), '/');
        $server = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'ntfy.sh').(isset($parts['port']) ? ':'.$parts['port'] : '');

        $request = Http::timeout(5)->asJson();
        $token = config('ring-master.notifications.ntfy_token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $request->post($server, [
            'topic' => $topic,
            'title' => 'RingMaster: '.$notification->title,
            'message' => implode("\n", $notification->lines) ?: $notification->title,
            'click' => $notification->url,
            'actions' => array_map(fn (array $action): array => [
                'action' => 'http',
                'label' => $action['label'],
                'url' => $action['url'],
                'method' => 'POST',
                'headers' => ['Accept' => 'application/json'],
                'clear' => true,
            ], array_slice($notification->actions, 0, 3)),
        ])->throw();
    }
}

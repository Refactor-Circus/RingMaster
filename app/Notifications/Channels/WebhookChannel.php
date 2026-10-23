<?php

namespace App\Notifications\Channels;

use App\Notifications\NeedsYou;
use Illuminate\Support\Facades\Http;

/**
 * POSTs {"text": ...}: the shape Slack and Discord incoming webhooks and ntfy accept.
 */
class WebhookChannel
{
    public function send(object $notifiable, NeedsYou $notification): void
    {
        Http::timeout(5)->asJson()->post((string) config('ring-master.notifications.webhook_url'), [
            'text' => $notification->text(),
            'content' => $notification->text(),
        ])->throw();
    }
}

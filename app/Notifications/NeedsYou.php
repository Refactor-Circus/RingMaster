<?php

namespace App\Notifications;

use App\Notifications\Channels\DesktopChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something in RingMaster waits on you. One notification covers a batch
 * (for example every question a scan found), so a busy scan sends one.
 */
class NeedsYou extends Notification
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{label: string, url: string}>  $actions  single-use buttons (ntfy shows them; mail lists them)
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines,
        public readonly string $url,
        public readonly ?string $only = null,
        public readonly array $actions = [],
    ) {}

    /**
     * The same notification for one channel, so a failing one can't stop the rest.
     */
    public function on(string $channel): self
    {
        return new self($this->title, $this->lines, $this->url, $channel, $this->actions);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        /** @var list<string> $channels */
        $channels = config('ring-master.notifications.channels', []);

        if ($this->only !== null) {
            return [$this->only];
        }

        return array_values(array_filter(array_map(fn (string $channel): ?string => match ($channel) {
            'mail' => config('ring-master.notifications.mail_to') ? 'mail' : null,
            'webhook' => config('ring-master.notifications.webhook_url') ? WebhookChannel::class : null,
            'desktop' => DesktopChannel::class,
            'ntfy' => config('ring-master.notifications.ntfy_url') ? NtfyChannel::class : null,
            default => null,
        }, $channels)));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('RingMaster: '.$this->title)->line($this->title);

        foreach ($this->lines as $line) {
            $mail->line('• '.$line);
        }

        foreach ($this->actions as $action) {
            $mail->line("{$action['label']}: {$action['url']}");
        }

        return $mail->action('Open RingMaster', $this->url);
    }

    public function text(): string
    {
        return $this->title.($this->lines === [] ? '' : "\n".implode("\n", array_map(fn (string $line): string => '• '.$line, $this->lines)))."\n".$this->url;
    }
}

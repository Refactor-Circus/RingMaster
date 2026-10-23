<?php

namespace App\Notifications\Channels;

use App\Notifications\NeedsYou;
use Illuminate\Support\Facades\Process;

/**
 * A notification on this machine's desktop: osascript on macOS, notify-send
 * on Linux. The text goes in as arguments, never into the script itself.
 */
class DesktopChannel
{
    public function send(object $notifiable, NeedsYou $notification): void
    {
        $body = implode("\n", array_slice($notification->lines, 0, 3)) ?: $notification->title;

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['osascript', '-e', 'on run argv', '-e', 'display notification (item 2 of argv) with title (item 1 of argv)', '-e', 'end run', 'RingMaster: '.$notification->title, $body],
            'Linux' => ['notify-send', '--app-name=RingMaster', 'RingMaster: '.$notification->title, $body],
            default => null,
        };

        if ($command !== null) {
            Process::timeout(5)->run($command);
        }
    }
}

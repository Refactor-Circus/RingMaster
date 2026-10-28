<?php

namespace App\Support;

use App\Models\ActionToken;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Buttons for notifications: each a single-use, signed link that does one
 * thing (accept a proposed job, approve a chore, answer a question with one
 * of its options, review low-risk PRs). It expires after phone.ttl_hours,
 * works once, and acts only on POST; opening it shows a confirmation page.
 * The links point at notifications.app_url, so set that to an address your
 * phone reaches privately (Tailscale, home Wi-Fi); never expose RingMaster
 * to the internet, it has no login.
 */
final class ActionLinks
{
    public const array ACTIONS = ['proposal.accept', 'proposal.decline', 'chore.approve', 'chore.dismiss', 'answer', 'review'];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{label: string, url: string}|null null when phone actions are off
     */
    public static function make(string $action, int $subjectId, string $label, ?array $payload = null): ?array
    {
        if (! config('ring-master.phone.actions', true) || ! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $token = ActionToken::query()->create([
            'token' => Str::random(48),
            'action' => $action,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'label' => Str::limit($label, 60),
            'expires_at' => now()->addHours(max(1, (int) config('ring-master.phone.ttl_hours', 24))),
        ]);

        // Signed relative to the path, so it holds whatever host the phone reaches RingMaster by.
        $path = URL::temporarySignedRoute('act', $token->expires_at, ['token' => $token->token], absolute: false);

        return ['label' => $token->label, 'url' => rtrim((string) config('ring-master.notifications.app_url'), '/').$path];
    }
}

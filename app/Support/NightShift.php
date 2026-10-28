<?php

namespace App\Support;

use App\Models\Run;
use Carbon\CarbonImmutable;

/**
 * The night shift: a window (22:00 to 07:00 by default) in which the runner
 * works the queue on a budget of its own, optionally with more sessions at
 * once. Once the night's runs have cost the budget, nothing new starts until
 * the window ends. Outside the window the runner works as it always does.
 */
final class NightShift
{
    public static function enabled(): bool
    {
        return (bool) config('ring-master.night_shift.enabled', true);
    }

    /**
     * When the window that contains $at began, or null outside it.
     */
    public static function startedAt(?CarbonImmutable $at = null): ?CarbonImmutable
    {
        if (! self::enabled()) {
            return null;
        }

        $at ??= CarbonImmutable::now();
        [$start, $end] = [self::time('start', '22:00'), self::time('end', '07:00')];
        $todayStart = $at->setTimeFromTimeString($start);
        $todayEnd = $at->setTimeFromTimeString($end);

        if ($start <= $end) {
            return $at->gte($todayStart) && $at->lt($todayEnd) ? $todayStart : null;
        }

        // The window crosses midnight: after today's start, or before today's end (it began yesterday).
        return match (true) {
            $at->gte($todayStart) => $todayStart,
            $at->lt($todayEnd) => $todayStart->subDay(),
            default => null,
        };
    }

    public static function active(?CarbonImmutable $at = null): bool
    {
        return self::startedAt($at) !== null;
    }

    public static function budget(): ?float
    {
        $budget = config('ring-master.night_shift.budget_usd');

        return is_numeric($budget) && (float) $budget > 0 ? (float) $budget : null;
    }

    /**
     * What tonight's runs have cost so far (0 outside the window).
     */
    public static function spent(): float
    {
        $since = self::startedAt();

        return $since === null ? 0.0 : (float) Run::query()->where('started_at', '>=', $since)->sum('cost_usd');
    }

    /**
     * Whether tonight's budget is used up, so nothing new may start.
     */
    public static function exhausted(): bool
    {
        $budget = self::budget();

        return $budget !== null && self::active() && self::spent() >= $budget;
    }

    /**
     * Sessions at once tonight, when the night shift sets its own.
     */
    public static function concurrency(): ?int
    {
        $set = config('ring-master.night_shift.concurrency');

        return self::active() && is_numeric($set) && (int) $set > 0 ? (int) $set : null;
    }

    private static function time(string $key, string $default): string
    {
        $value = config("ring-master.night_shift.{$key}", $default);

        return is_string($value) && preg_match('/^\d{1,2}:\d{2}$/', $value) ? str_pad($value, 5, '0', STR_PAD_LEFT) : $default;
    }
}

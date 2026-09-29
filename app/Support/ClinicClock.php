<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The clinic's wall clock.
 *
 * The app runs on UTC, while appointments are stored as the clinic's local
 * wall-clock digits (see the Phase A1 timezone notes). Queue and case-number
 * logic compare against those digits, so "now" has to be the clinic's local
 * time carrying the app timezone label — the same convention Eloquent uses
 * when it reads `scheduled_at` back.
 */
class ClinicClock
{
    public static function timezone(): string
    {
        return config('reception.timezone', 'Asia/Kolkata');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->shiftTimezone(config('app.timezone'));
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /**
     * A real UTC instant (e.g. a `created_at`) as clinic wall-clock digits.
     */
    public static function fromUtc(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->utc()->setTimezone(self::timezone())->shiftTimezone(config('app.timezone'));
    }
}

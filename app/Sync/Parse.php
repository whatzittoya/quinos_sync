<?php

namespace App\Sync;

use Carbon\CarbonImmutable;

/** Turns Quinos's JSON values into what we store. */
final class Parse
{
    /** "2026-09-29T00:00:00.000000Z" -> "2026-09-29" (the date Quinos shows, no timezone shift). */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        return substr($value, 0, 10);
    }

    public static function time(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function str(mixed $value, int $max = 255): ?string
    {
        return filled($value) ? mb_substr((string) $value, 0, $max) : null;
    }
}

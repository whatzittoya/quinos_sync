<?php

namespace App\Sync;

use Illuminate\Support\Facades\Cache;

/** One line saying what a client's running sync is doing, for the Sync page. */
final class SyncProgress
{
    public static function set(int $clientId, string $text): void
    {
        Cache::put("sync-progress:{$clientId}", $text, 1800);
    }

    public static function get(int $clientId): ?string
    {
        return Cache::get("sync-progress:{$clientId}");
    }

    public static function clear(int $clientId): void
    {
        Cache::forget("sync-progress:{$clientId}");
    }
}

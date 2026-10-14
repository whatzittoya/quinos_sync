<?php

namespace App\Sync;

use Illuminate\Support\Facades\Cache;

/**
 * Starts the queue worker as a background process from a web request, so syncs do not wait for
 * cron and are not cut off by the web server's request time limit. The `sync:work` command sets
 * a cache flag while it runs, so a second worker is not started on top of it.
 */
final class WorkerLauncher
{
    private const FLAG = 'sync-worker-running';

    public static function canSpawn(): bool
    {
        return config('sync.background_worker')
            && ! app()->runningInConsole()
            && self::execAvailable()
            && self::phpBinary() !== null;
    }

    public static function isRunning(): bool
    {
        return Cache::has(self::FLAG);
    }

    /** Called by `sync:work`. */
    public static function markRunning(): void
    {
        Cache::put(self::FLAG, now()->toIso8601String(), 3900); // expires by itself if the process dies
    }

    public static function markStopped(): void
    {
        Cache::forget(self::FLAG);
    }

    /** Start a worker unless one is already running. True if one is running afterwards. */
    public static function ensureRunning(): bool
    {
        if (self::isRunning()) {
            return true;
        }

        if (! self::canSpawn()) {
            return false;
        }

        // Claim the flag now, so a burst of requests starts one worker, not many.
        self::markRunning();

        if (PHP_OS_FAMILY === 'Windows') {
            // `start /B` returns at once and keeps running after the request ends.
            pclose(popen(sprintf('start /B "" %s "%s" sync:work > NUL 2>&1', escapeshellarg(self::phpBinary()), base_path('artisan')), 'r'));

            return true;
        }

        exec(sprintf(
            'cd %s && nohup %s artisan sync:work > /dev/null 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(self::phpBinary()),
        ));

        return true;
    }

    /** The command-line PHP (the web server's own PHP binary cannot run artisan). */
    private static function phpBinary(): ?string
    {
        foreach ([config('sync.php_cli'), '/usr/local/bin/php', '/usr/bin/php'] as $path) {
            if (is_string($path) && $path !== '' && is_executable($path)) {
                return $path;
            }
        }

        // On Windows the PHP that serves the page is php.exe for `artisan serve`, but httpd.exe under XAMPP.
        if (PHP_OS_FAMILY === 'Windows') {
            return str_ends_with(strtolower(PHP_BINARY), 'php.exe') ? PHP_BINARY : 'php';
        }

        return null;
    }

    private static function execAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && ! in_array('exec', $disabled, true);
    }
}

<?php

return [

    // Data packages synced for every day.
    'packages' => ['purchase', 'sales'],

    // Quinos lists come newest-created first. A day sync stops reading a list
    // once it reaches documents created more than this many days before the
    // earliest requested date (documents can be back-dated, but rarely by more).
    // "Re-sync" ignores this limit and reads the whole list.
    'lookback_days' => (int) env('SYNC_LOOKBACK_DAYS', 45),

    // Items, departments, contacts and sales types are refreshed at most this often.
    'master_ttl_hours' => (int) env('SYNC_MASTER_TTL_HOURS', 6),

    // Start a queue worker in the background from the web request (no cron, no web time limit).
    // Needs exec() and a command-line PHP; if not possible, the options below are tried.
    'background_worker' => (bool) env('SYNC_BACKGROUND_WORKER', true),
    'php_cli' => env('SYNC_PHP_CLI'), // e.g. /usr/local/bin/php; found automatically when empty

    // Run a sync right after the web response instead of waiting for the queue worker.
    // Needs php-fpm or LiteSpeed; elsewhere (and in the console) syncs are queued as before.
    'run_after_response' => (bool) env('SYNC_AFTER_RESPONSE', true),

    // Most days one request may queue.
    'max_days_per_request' => 62,

];

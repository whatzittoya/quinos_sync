<?php
return [
    'api_url' => rtrim(env('RUNNER_API_URL', ''), '/'),
    'token' => env('RUNNER_TOKEN', ''),
    'chunk_size' => (int) env('RUNNER_CHUNK_SIZE', 100),
    // Mobile API bills carry all their lines, so they go up in smaller chunks (hosted accepts at most 2 MB per chunk).
    'pos_chunk_size' => (int) env('RUNNER_POS_CHUNK_SIZE', 25),
    // Scheduled report runs also re-check this many days back and sync days that are missing (0 = only yesterday and today, max 60).
    'catchup_days' => (int) env('RUNNER_CATCHUP_DAYS', 7),
    // Connection errors to the hosted app are retried this many times in all, waiting between attempts.
    'retries' => (int) env('RUNNER_RETRIES', 4),
    'retry_delay_ms' => (int) env('RUNNER_RETRY_DELAY_MS', 3000),
    'connect_timeout' => (int) env('RUNNER_CONNECT_TIMEOUT', 20),
    'timeout' => (int) env('RUNNER_TIMEOUT', 60),
    'task_name' => env('RUNNER_TASK_NAME', 'Quinos Local Sync'),
];

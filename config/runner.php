<?php
return [
    'api_url' => rtrim(env('RUNNER_API_URL', ''), '/'),
    'token' => env('RUNNER_TOKEN', ''),
    'chunk_size' => (int) env('RUNNER_CHUNK_SIZE', 100),
    // Scheduled report runs also re-check this many days back and sync days that are missing (0 = only yesterday and today, max 60).
    'catchup_days' => (int) env('RUNNER_CATCHUP_DAYS', 7),
    'timeout' => (int) env('RUNNER_TIMEOUT', 60),
    'task_name' => env('RUNNER_TASK_NAME', 'Quinos Local Sync'),
];

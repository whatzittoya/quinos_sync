<?php
return [
    'api_url' => rtrim(env('RUNNER_API_URL', ''), '/'),
    'token' => env('RUNNER_TOKEN', ''),
    'chunk_size' => (int) env('RUNNER_CHUNK_SIZE', 100),
    'timeout' => (int) env('RUNNER_TIMEOUT', 60),
    'task_name' => env('RUNNER_TASK_NAME', 'Quinos Local Sync'),
];

<?php

return [

    // The POS backend we read data from.
    'base_url' => rtrim(env('QUINOS_BASE_URL', 'https://quinosbackend.com'), '/'),

    // Minimum pause between two requests of one client. Be gentle with their server.
    'delay_ms' => (int) env('QUINOS_DELAY_MS', 300),

    'timeout' => (int) env('QUINOS_TIMEOUT', 30),

    // Retries on connection errors and 5xx answers (not on 4xx).
    'max_attempts' => (int) env('QUINOS_MAX_ATTEMPTS', 3),
    'retry_delay_ms' => (int) env('QUINOS_RETRY_DELAY_MS', 800),

    // Safety stop for cursor pagination.
    'max_pages' => (int) env('QUINOS_MAX_PAGES', 500),

];

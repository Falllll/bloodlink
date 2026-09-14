<?php

declare(strict_types=1);

return [
    'rate_limit' => [
        'per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
        'auth_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),
    ],
    'hsts_max_age' => env('HSTS_MAX_AGE', 31536000),
];

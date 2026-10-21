<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public API v1 and CLI sign-in (docs/16)
|--------------------------------------------------------------------------
|
| Token lifetimes, rate limits, idempotency retention, the CLI device flow
| and the server-sent event streams. Tokens always expire; the choices in
| Settings → API tokens are the lifetimes below (days).
|
*/

return [
    'token_lifetimes' => [7, 30, 90, 365],
    'max_token_days' => 365,
    'cli_token_days' => 90,

    'rate_limits' => [
        'requests_per_minute' => (int) env('LAUNCHROOM_API_RATE_LIMIT', 300),
        'writes_per_minute' => (int) env('LAUNCHROOM_API_WRITE_RATE_LIMIT', 60),
        'cli_starts_per_minute' => 10,
        'cli_polls_per_minute' => 60,
    ],

    'idempotency_hours' => 24,

    'cli_login' => [
        'expires_seconds' => 600,
        'interval_seconds' => 2,
    ],

    'streams' => [
        'max_seconds' => (int) env('LAUNCHROOM_API_STREAM_SECONDS', 300),
        'poll_milliseconds' => 1000,
        'ping_seconds' => 15,
    ],

    'page_size' => 25,
    'max_page_size' => 100,
];

<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Query of the log explorer endpoints: `range` (15m, 1h, 24h, 7d, 30d),
 * `source` (all; runtime: everything but build output; build: the plan,
 * build and release output of deployments), an optional `service`, the search `q`
 * (App\Support\Logs\LogSearchQuery) and the live cursor `after` (the last
 * chunk id seen).
 */
final class LogQueryData extends Data
{
    public const array RANGES = ['15m' => 900, '1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function __construct(
        #[In(['15m', '1h', '24h', '7d', '30d'])]
        public string $range = '1h',
        #[In(['all', 'runtime', 'build'])]
        public string $source = 'all',
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public ?string $service = null,
        #[Min(0)]
        public int $after = 0,
        #[Max(512)]
        public ?string $q = null,
    ) {}

    public function seconds(): int
    {
        return self::RANGES[$this->range] ?? 3600;
    }
}

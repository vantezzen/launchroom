<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Enums\MetricRange;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Query string of the metrics endpoints: `?range=1h|6h|24h|7d|30d|90d|1y`
 * (default 1h); the project endpoint also takes `environment` (default
 * production) and `service` (public ids).
 */
final class MetricsQueryData extends Data
{
    public function __construct(
        public MetricRange $range = MetricRange::Hour,
        #[Regex('/^env_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public ?string $environment = null,
        #[Regex('/^svc_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public ?string $service = null,
    ) {}
}

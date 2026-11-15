<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use App\Data\Attributes\PublicIdOf;
use App\Enums\MetricRange;
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
        #[PublicIdOf('env')]
        public ?string $environment = null,
        #[PublicIdOf('svc')]
        public ?string $service = null,
    ) {}
}

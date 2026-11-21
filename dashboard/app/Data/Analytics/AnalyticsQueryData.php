<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Data\Attributes\PublicIdOf;
use App\Enums\AnalyticsRange;
use Spatie\LaravelData\Data;

/**
 * Query string of the analytics endpoints (docs/15 §5.4):
 * `?range=1h|24h|7d|30d` (default 7d), `environment` (default production,
 * dashboard only) and `service` (public ids).
 */
final class AnalyticsQueryData extends Data
{
    public function __construct(
        public AnalyticsRange $range = AnalyticsRange::Week,
        #[PublicIdOf('env')]
        public ?string $environment = null,
        #[PublicIdOf('svc')]
        public ?string $service = null,
    ) {}
}

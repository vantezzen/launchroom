<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Enums\AnalyticsRange;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Query string of the analytics endpoints (docs/15 §5.4):
 * `?range=24h|7d|30d` (default 7d), `environment` (default production,
 * dashboard only) and `service` (public ids).
 */
final class AnalyticsQueryData extends Data
{
    public function __construct(
        public AnalyticsRange $range = AnalyticsRange::Week,
        #[Regex('/^env_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public ?string $environment = null,
        #[Regex('/^svc_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public ?string $service = null,
    ) {}
}

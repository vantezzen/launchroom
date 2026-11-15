<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * One entry of `instance_settings.dashboard_legacy_urls`: an earlier
 * dashboard origin that keeps answering, and until when it is in transition
 * (App\Services\Instance\DashboardOriginSwitch).
 */
final class LegacyOriginEntryData extends Data
{
    public function __construct(
        public string $url,
        public string $since,
        public string $until,
    ) {}
}

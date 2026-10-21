<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * Which dashboard address a server's launchroomd uses (Report.host.controlPlane).
 */
final class DashboardServerUrlData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** `configured`, `adopted`, `fallback`, `pending`, `refused` or `unknown` (no report: older launchroomd). */
        public string $state,
        /** The URL it talks to now. */
        public ?string $url,
        public ?string $message,
        /** It reached the dashboard at the canonical origin. */
        public bool $current,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Where the dashboard runs now (Settings → Dashboard domain, docs/05 §4.1).
 */
final class DashboardOriginData extends Data
{
    public function __construct(
        public string $url,
        public string $host,
        public bool $https,
        /** An IP address origin (no certificate, no passkeys). */
        public bool $ip,
        /** Chosen in Settings (not APP_URL). */
        public bool $switched,
        public string $installUrl,
        public ?CarbonInterface $switchedAt,
    ) {}
}

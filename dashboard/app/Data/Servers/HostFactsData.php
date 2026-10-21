<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * `servers.facts`: what the daemon reported about its host at enrollment
 * (informational, shown in the UI). `hardware` is refreshed from reports
 * (Report.host.hardware) and drives device detection.
 */
final class HostFactsData extends Data
{
    public function __construct(
        public ?string $hostname = null,
        public ?string $os = null,
        public ?string $arch = null,
        public ?int $cpus = null,
        public ?int $memoryMb = null,
        public ?HardwareFactsData $hardware = null,
    ) {}
}

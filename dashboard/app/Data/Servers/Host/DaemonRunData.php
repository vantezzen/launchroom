<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/** When launchroomd and the machine last started (Report.host.daemon). */
final class DaemonRunData extends Data
{
    public function __construct(
        public string $startedAt,
        public ?string $hostBootedAt = null,
    ) {}
}

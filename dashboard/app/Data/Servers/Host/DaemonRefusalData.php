<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use Spatie\LaravelData\Data;

/**
 * The last answer launchroomd got that refused it (Report.host.lastRefusal):
 * the HTTP status, how far its clock was from the dashboard's `Date`
 * header, and the dashboard's error text. Delivered with the next accepted
 * report.
 */
final class DaemonRefusalData extends Data
{
    public function __construct(
        public string $at,
        public int $status,
        public ?int $clockSkewMs = null,
        public ?string $message = null,
    ) {}
}

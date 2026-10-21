<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Data;

/**
 * A deployment on the log histogram (signal tick with the short SHA).
 */
final class LogMarkerData extends Data
{
    public function __construct(
        public string $deploymentId,
        public string $at,
        public string $label,
        public string $status,
    ) {}
}

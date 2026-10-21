<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * A deploy marker on usage charts: when a deployment of the service finished
 * (or started, while it runs).
 */
final class DeployMarkerData extends Data
{
    public function __construct(
        public string $deploymentId,
        public string $serviceId,
        public string $at,
        public string $status,
        public ?string $serviceName = null,
        /** Short commit SHA (7), when the deployment built a commit. */
        public ?string $sha = null,
    ) {}
}

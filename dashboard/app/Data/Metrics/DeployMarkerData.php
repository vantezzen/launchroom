<?php

declare(strict_types=1);

namespace App\Data\Metrics;

use Spatie\LaravelData\Data;

/**
 * A deploy on a time chart (docs/15 §2, design-system §9): when a
 * deployment of a service finished (or started, while it runs). One shape
 * for every chart (Metrics, Logs, Analytics, Security), built by
 * App\Queries\DeployMarkers; `href` opens the deployment.
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
        /** The deployment page. */
        public ?string $href = null,
    ) {}
}

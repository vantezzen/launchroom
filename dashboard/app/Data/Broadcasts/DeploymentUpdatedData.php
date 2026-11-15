<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use App\Enums\DeploymentStatus;
use Spatie\LaravelData\Data;

/**
 * `deployment.updated`. `statusChanged` is true only when the status itself
 * just changed: other nudges (pull progress, a build being picked up) resend
 * the current status, and outcome toasts must not announce those again.
 */
final class DeploymentUpdatedData extends Data
{
    public function __construct(
        public string $deploymentId,
        public string $environmentId,
        public string $serviceId,
        public DeploymentStatus $status,
        public bool $statusChanged,
    ) {}
}

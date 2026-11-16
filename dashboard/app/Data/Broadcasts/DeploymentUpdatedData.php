<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use App\Enums\DeploymentStatus;
use Spatie\LaravelData\Data;

/**
 * `deployment.updated`. `statusChanged` is true only when the status itself
 * just changed: other nudges (pull progress, a build being picked up) resend
 * the current status, and outcome toasts must not announce those again.
 * Status changes also go to the member channel of whoever started the
 * deployment, for the toasts that follow them through the app.
 */
final class DeploymentUpdatedData extends Data
{
    public function __construct(
        public string $deploymentId,
        public string $environmentId,
        public string $serviceId,
        public DeploymentStatus $status,
        public bool $statusChanged,
        /** Set on status changes: the toasts of the member who started it link and name it without a refetch. */
        public ?string $projectId = null,
        public ?string $serviceName = null,
        public ?string $commitSha = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Spatie\LaravelData\Data;

/**
 * A deployment of one service in one environment. `finished` is true once
 * nothing more happens on its own: live (`active`), `failed`, `cancelled`,
 * `superseded`, or `ready` (a staged deployment waiting for Promote).
 */
final class DeploymentResource extends Data
{
    public function __construct(
        public string $id,
        public string $environmentId,
        public string $serviceId,
        public string $serviceName,
        public DeploymentStatus $status,
        public bool $finished,
        public DeploymentTrigger $trigger,
        public ?ApiCommitData $commit,
        public ?ApiFailureData $failure,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public string $dashboardUrl,
    ) {}
}

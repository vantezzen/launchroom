<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Enums\DeploymentStatus;
use Spatie\LaravelData\Data;

/**
 * `status` of a deployment log stream: sent when the status changes;
 * `finished` once the deployment settled.
 */
final class DeploymentStatusEvent extends Data
{
    public function __construct(
        public DeploymentStatus $status,
        public bool $finished,
    ) {}
}

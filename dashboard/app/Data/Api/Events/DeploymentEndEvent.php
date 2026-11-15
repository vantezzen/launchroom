<?php

declare(strict_types=1);

namespace App\Data\Api\Events;

use App\Enums\DeploymentStatus;
use Spatie\LaravelData\Data;

/**
 * `end` of a deployment log stream: the deployment settled with `status`.
 */
final class DeploymentEndEvent extends Data
{
    public function __construct(
        public DeploymentStatus $status,
    ) {}
}

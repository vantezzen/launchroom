<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One recorded step of a deployment's timeline (docs/04 §6). `message` is
 * the step's user-facing outcome; step meta beyond that is never exposed.
 */
final class DeploymentStepData extends Data
{
    public function __construct(
        public DeploymentStepName $name,
        public DeploymentStepStatus $status,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public ?string $message,
    ) {}
}

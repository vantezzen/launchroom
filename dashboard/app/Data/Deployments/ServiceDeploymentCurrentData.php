<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use Spatie\LaravelData\Data;

/**
 * The expanded first row of a service's deployments with its recorded steps.
 */
final class ServiceDeploymentCurrentData extends Data
{
    /**
     * @param  list<DeploymentStepData>  $steps
     */
    public function __construct(
        public string $id,
        public array $steps,
        /** Show "Release" from the start (docs/04 §6). */
        public bool $expectsRelease,
    ) {}
}

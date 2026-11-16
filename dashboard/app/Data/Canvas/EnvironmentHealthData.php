<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * The open environment in the project top bar (docs/08 §1): its rolled-up
 * status beside the environment switcher, and whether a deploy runs or
 * the latest one failed, for the Deployments tab.
 */
final class EnvironmentHealthData extends Data
{
    public function __construct(
        public NodeStatusData $status,
        public bool $deploying,
        public bool $deployFailed,
    ) {}
}

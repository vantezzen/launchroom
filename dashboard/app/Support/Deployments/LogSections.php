<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Models\Deployment;

/**
 * The step of a deployment's log that a container lifecycle line or a
 * starting container's output belongs to (D68): the step the deployment is
 * in, the one it ended in when it failed or was cancelled, and `runtime`
 * once it is live.
 */
final class LogSections
{
    public static function current(Deployment $deployment): string
    {
        return match ($deployment->status) {
            DeploymentStatus::Queued, DeploymentStatus::Building, DeploymentStatus::Releasing, DeploymentStatus::RollingOut => DeploymentStepName::Rollout->value,
            DeploymentStatus::HealthChecking, DeploymentStatus::Ready => DeploymentStepName::Health->value,
            DeploymentStatus::Routing => DeploymentStepName::Route->value,
            DeploymentStatus::Failed, DeploymentStatus::Cancelled => self::lastStep($deployment),
            default => 'runtime',
        };
    }

    private static function lastStep(Deployment $deployment): string
    {
        $name = $deployment->steps()
            ->whereIn('name', [DeploymentStepName::Rollout->value, DeploymentStepName::Health->value, DeploymentStepName::Route->value])
            ->orderByDesc('id')
            ->value('name');

        return match (true) {
            $name instanceof DeploymentStepName => $name->value,
            is_string($name) => $name,
            default => DeploymentStepName::Rollout->value,
        };
    }
}

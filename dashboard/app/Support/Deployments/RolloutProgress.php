<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use Illuminate\Support\Collection;

/**
 * The running Roll out and Health check steps' current sub-step for the
 * deployment page (D68), from the placements launchroomd reports on.
 */
final class RolloutProgress
{
    /**
     * @param  Collection<int, DeploymentStep>  $steps
     * @return array<string, string> step name => what it is doing
     */
    public static function for(Deployment $deployment, Collection $steps): array
    {
        $running = $steps->filter(static fn (DeploymentStep $step): bool => $step->status === DeploymentStepStatus::Running
            && in_array($step->name, [DeploymentStepName::Rollout, DeploymentStepName::Health], true));

        if ($running->isEmpty()) {
            return [];
        }

        $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $effective = $spec instanceof ServiceSpec ? EffectiveDeploy::for($spec, $deployment) : null;
        $rollout = $steps->first(static fn (DeploymentStep $step): bool => $step->name === DeploymentStepName::Rollout);
        /** @var array<int|string, int> $versions */
        $versions = (array) ($rollout?->meta['versions'] ?? []);
        $status = new RolloutStatus($deployment->placements()->get(), $versions, $effective?->port, $effective?->healthcheck, $deployment->image);
        $out = [];

        foreach ($running as $step) {
            $message = $step->name === DeploymentStepName::Rollout ? $status->rollout() : $status->health();

            if ($message !== null) {
                $out[$step->name->value] = $message;
            }
        }

        return $out;
    }
}

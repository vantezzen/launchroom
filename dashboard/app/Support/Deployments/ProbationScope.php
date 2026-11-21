<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentPurpose;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Models\Deployment;

/**
 * Which deployments CheckProbation watches after go-live (docs/04 §8, OD1).
 */
final class ProbationScope
{
    /**
     * Whether a deployment that just went live is watched: production, an
     * app or image service, a new version (not a rerun of an image). An
     * image with a volume is often a database kept as a container, whose
     * data an older version may not read: never, except for an app update
     * (docs/04 §14.2).
     */
    public static function watches(Deployment $deployment, ServiceSpec $spec): bool
    {
        // An app update (D106) is watched in every lasting environment, also
        // with volumes: its data was backed up right before.
        if ($deployment->trigger === DeploymentTrigger::Update) {
            return $deployment->environment->kind !== EnvironmentKind::Preview
                && $deployment->purpose === DeploymentPurpose::Release
                && $deployment->rollback_reason === null;
        }

        return $deployment->environment->kind === EnvironmentKind::Production
            && $spec->kind->deploysNewVersions()
            && ($spec->kind !== ServiceKind::Image || $spec->volumes === [])
            && $deployment->purpose === DeploymentPurpose::Release
            && ! $deployment->trigger->rerunsImage()
            && $deployment->rollback_reason === null;
    }
}

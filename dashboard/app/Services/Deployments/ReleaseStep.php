<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Data\Protocol\V1\AppliedPreset;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Services\ServicePlanData;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Support\Deployments\EffectiveDeploy;

/**
 * Whether a deployment's step list shows "Release" (docs/04 §6, §13): the
 * release command is often only known from the build summary, so the step
 * would appear out of nowhere after the build. It is shown from the start
 * when the service has a release command of its own, its build summary
 * has one, or its plan preview found one (the Laravel preset's migrations).
 * When the build then reveals none, the UI marks the step skipped.
 * Restarts, starts and rollbacks never run it.
 */
final class ReleaseStep
{
    public static function expected(Deployment $deployment): bool
    {
        if ($deployment->trigger->rerunsImage()) {
            return false;
        }

        // Callers may have loaded the instance and service with a few
        // columns only (DeploymentSummaries::withRelations): the kind comes
        // from the revision's spec, the plan preview from its own query.
        $deployment->loadMissing(['revision', 'serviceInstance.service']);
        $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);

        if ($spec === null) {
            return false;
        }

        if (EffectiveDeploy::for($spec, $deployment)->releaseCommand !== null) {
            return true;
        }

        if ($spec->kind !== ServiceKind::App) {
            return false;
        }

        $instance = ServiceInstance::query()->whereKey($deployment->service_instance_id)->first(['id', 'plan']);

        return $instance instanceof ServiceInstance && self::likely(ServicePlanData::forInstance($instance)?->summary);
    }

    private static function likely(?BuildSummary $summary): bool
    {
        if (! $summary instanceof BuildSummary) {
            return false;
        }

        return ($summary->releaseCommand ?? []) !== []
            || $summary->framework === 'laravel'
            || array_any($summary->presets ?? [], static fn (AppliedPreset $preset): bool => $preset->name === 'laravel');
    }
}

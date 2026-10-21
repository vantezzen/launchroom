<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Promotions\PromotionEnvironmentCardData;
use App\Enums\DeploymentStatus;
use App\Models\Environment;
use App\Models\ServiceInstance;

/**
 * The two environment cards of Compare & promote: live revision, when it
 * was deployed, and how many services serve the current version.
 */
final class PromotionEnvironmentCards
{
    public function card(Environment $environment): PromotionEnvironmentCardData
    {
        $environment->loadMissing('lastAppliedRevision:id,number,created_at');
        $instances = ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            ->with('activeDeployment:id,status')
            ->get(['id', 'active_deployment_id']);

        return new PromotionEnvironmentCardData(
            id: $environment->public_id,
            name: $environment->name,
            kind: $environment->kind,
            revision: $environment->lastAppliedRevision?->number,
            deployedAt: $environment->lastAppliedRevision?->created_at->toIso8601String(),
            services: $instances->count(),
            live: $instances->filter(static fn (ServiceInstance $instance): bool => $instance->activeDeployment?->status === DeploymentStatus::Active)->count(),
        );
    }
}

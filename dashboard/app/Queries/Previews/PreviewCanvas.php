<?php

declare(strict_types=1);

namespace App\Queries\Previews;

use App\Data\Previews\PreviewCanvasData;
use App\Data\Previews\PreviewEdgeBadgeData;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\PreviewStrategy;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;

/**
 * The pull request context of a preview environment's canvas (null for
 * other environments), including the badges for shared and cloned databases.
 */
final class PreviewCanvas
{
    public function for(Environment $environment): ?PreviewCanvasData
    {
        if ($environment->kind !== EnvironmentKind::Preview) {
            return null;
        }

        $preview = Preview::query()->where('environment_id', $environment->id)->first();

        if (! $preview instanceof Preview) {
            return null;
        }

        $environment->loadMissing(['baseEnvironment', 'project']);
        $base = $environment->baseEnvironment->name ?? 'the base environment';
        $badges = [];

        $instances = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->whereIn('preview_strategy', [PreviewStrategy::Share->value, PreviewStrategy::Clone->value])
            ->with(['service', 'previewCloneSource.environment', 'previewCloneSource.service'])
            ->get();

        foreach ($instances as $instance) {
            if (! $instance->service->kind->isStateful()) {
                continue;
            }

            $badges[] = $instance->preview_strategy === PreviewStrategy::Share
                ? new PreviewEdgeBadgeData($instance->service->public_id, $instance->service->name, "shared with {$base}", 'danger')
                : new PreviewEdgeBadgeData($instance->service->public_id, $instance->service->name, 'cloned from '.($instance->previewCloneSource === null ? 'a backup' : $instance->previewCloneSource->environment->name.' / '.$instance->previewCloneSource->service->name), 'neutral');
        }

        // Databases that would be shared or cloned but are new and empty
        // instead (fork previews, docs/09 R20; production data not allowed).
        $states = is_array($preview->data['databases'] ?? null) ? $preview->data['databases'] : [];
        $downgraded = array_filter($states, static fn (mixed $state): bool => is_array($state) && is_string($state['downgradedFrom'] ?? null));

        if ($downgraded !== []) {
            $names = [];

            foreach (ServiceInstance::query()->where('environment_id', $environment->id)->with('service')->get() as $instance) {
                $names[$instance->service->public_id] = $instance->service->name;
            }

            foreach ($downgraded as $serviceId => $state) {
                if (is_string($serviceId) && isset($names[$serviceId])) {
                    $label = ($state['downgradeReason'] ?? null) === 'production' ? 'new and empty (production data not allowed)' : 'new and empty (fork: no base data)';
                    $badges[] = new PreviewEdgeBadgeData($serviceId, $names[$serviceId], $label, 'neutral');
                }
            }
        }

        $settings = PreviewSettings::forProject($environment->project);
        $statuses = Deployment::query()
            ->where('environment_id', $environment->id)
            ->whereNotIn('status', [DeploymentStatus::Superseded->value, DeploymentStatus::Cancelled->value])
            ->orderByDesc('id')
            ->get(['id', 'service_instance_id', 'status'])
            ->unique('service_instance_id')
            ->pluck('status');

        return new PreviewCanvasData(
            id: $preview->public_id,
            number: $preview->pr_number,
            title: $preview->title,
            headRef: $preview->head_ref,
            baseName: $base,
            status: $preview->status,
            statusLabel: $preview->status->label(),
            health: match (true) {
                $statuses->contains(DeploymentStatus::Failed) => 'failed',
                $statuses->isEmpty() => 'stopped',
                $statuses->every(static fn (DeploymentStatus $status): bool => $status === DeploymentStatus::Active) => 'live',
                default => 'building',
            },
            statusReason: $preview->status_reason,
            secretsWithheld: $environment->secrets_withheld,
            expiresAt: $settings->expiry_days > 0 ? $preview->expiresAt($settings->expiry_days)->toIso8601String() : null,
            edgeBadges: $badges,
        );
    }
}

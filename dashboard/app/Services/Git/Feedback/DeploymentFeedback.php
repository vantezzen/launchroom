<?php

declare(strict_types=1);

namespace App\Services\Git\Feedback;

use App\Data\Git\DeploymentStatusReport;
use App\Data\Services\GitSource;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\Git\DeploymentReportState;
use App\Models\Deployment;
use App\Support\Variables\SystemVariables;

/**
 * What the git provider learns about a deployment (docs/06 §5, docs/19):
 * only deployments that can go live (`purpose = release`) of a commit from a
 * provider with an API. A prebuild during a server move runs the live commit
 * but is never reported, or it would show up as a production deployment.
 *
 * queued → Queued; building … routing → InProgress; active → Success;
 * failed → Failure; cancelled/superseded → Cancelled. Only changes of the
 * reported state are sent (building → releasing is still "in progress"),
 * and a live deployment being replaced stays "success" (its provider
 * deployment goes inactive on its own).
 */
final readonly class DeploymentFeedback
{
    /**
     * The state to report for a status change, or null when the provider
     * already shows the right one.
     */
    public static function change(DeploymentStatus $from, DeploymentStatus $to): ?DeploymentReportState
    {
        if ($from === DeploymentStatus::Active || DeploymentReportState::forStatus($to) === DeploymentReportState::forStatus($from)) {
            return null;
        }

        return DeploymentReportState::forStatus($to);
    }

    public function report(Deployment $deployment, DeploymentReportState $state): ?DeploymentStatusReport
    {
        $sha = $deployment->commit_sha;

        if (! $deployment->purpose->goesLive() || $sha === null) {
            return null;
        }

        $deployment->loadMissing(['serviceInstance.service', 'environment.project', 'revision']);
        $service = $deployment->serviceInstance->service;
        $source = $service->source;

        if (! $source instanceof GitSource || ! $source->provider->hasApi() || $source->installationId === null) {
            return null;
        }

        $environment = $deployment->environment;
        $spec = $deployment->revision?->spec->service($service->public_id);
        $domain = $spec === null ? null : SystemVariables::primaryDomain($spec);

        return new DeploymentStatusReport(
            teamId: $deployment->team_id,
            installationId: $source->installationId,
            repositoryId: $source->repositoryId,
            deploymentKey: $deployment->public_id,
            serviceName: $service->name,
            environmentName: $environment->kind === EnvironmentKind::Preview && $environment->pr_number !== null ? "preview/pr-{$environment->pr_number}" : $environment->name,
            productionEnvironment: $environment->kind === EnvironmentKind::Production,
            transientEnvironment: $environment->kind === EnvironmentKind::Preview,
            commitSha: $sha,
            state: $state,
            deploymentUrl: route('deployments.show', ['project' => $environment->project->public_id, 'environment' => $environment->public_id, 'deployment' => $deployment->public_id]),
            environmentUrl: $domain === null ? null : "https://{$domain}",
            description: $deployment->status === DeploymentStatus::Failed ? $deployment->failure_message : null,
            pullRequestNumber: $environment->pr_number,
            deploymentApi: $environment->kind !== EnvironmentKind::Preview,
            provider: $source->provider->value,
        );
    }
}

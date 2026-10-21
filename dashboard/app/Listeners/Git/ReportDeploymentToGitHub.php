<?php

declare(strict_types=1);

namespace App\Listeners\Git;

use App\Data\Git\DeploymentStatusReport;
use App\Data\Services\GitSource;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\Git\DeploymentReportState;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Models\Deployment;
use App\Support\Variables\SystemVariables;

/**
 * Provider feedback (docs/06 §5, docs/19) for deployments of GitHub-, GitLab-
 * and Gitea-sourced services
 * with a commit: queued → Queued; building … routing → InProgress; active →
 * Success; failed → Failure; cancelled/superseded → Cancelled. Only state
 * changes that change the reported state are sent (building → releasing is
 * still "in progress"). Reporting runs in a job and never affects the
 * deployment.
 */
final class ReportDeploymentToGitHub
{
    public function handle(DeploymentStatusChanged $event): void
    {
        // A live deployment being replaced stays "success" on GitHub (its
        // GitHub deployment goes inactive through auto_inactive).
        if ($event->from === DeploymentStatus::Active || self::state($event->to) === self::state($event->from)) {
            return;
        }

        $deployment = Deployment::query()->find($event->deploymentId);

        if ($deployment instanceof Deployment) {
            self::report($deployment, self::state($event->to));
        }
    }

    /**
     * Queues the GitHub report for $deployment when it is a GitHub-sourced
     * deployment of a commit (also used for the initial "queued" of pushes).
     */
    public static function report(Deployment $deployment, DeploymentReportState $to): void
    {
        $deployment->loadMissing(['serviceInstance.service', 'environment.project', 'revision']);
        $source = $deployment->serviceInstance->service->source;

        if (! $source instanceof GitSource || ! $source->kind()->hasApi() || $source->installationId === null || $deployment->commit_sha === null) {
            return;
        }

        $environment = $deployment->environment;
        $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $domain = $spec === null ? null : SystemVariables::primaryDomain($spec);

        dispatch(new ReportDeploymentStatusJob(new DeploymentStatusReport(
            teamId: $deployment->team_id,
            installationId: $source->installationId,
            repositoryId: $source->repositoryId,
            deploymentKey: $deployment->public_id,
            serviceName: $deployment->serviceInstance->service->name,
            // Previews: GitHub environment `preview/pr-<n>` (docs/06 §5).
            environmentName: $environment->kind === EnvironmentKind::Preview && $environment->pr_number !== null ? "preview/pr-{$environment->pr_number}" : $environment->name,
            productionEnvironment: $environment->kind === EnvironmentKind::Production,
            transientEnvironment: $environment->kind === EnvironmentKind::Preview,
            commitSha: $deployment->commit_sha,
            state: $to,
            deploymentUrl: route('deployments.show', ['project' => $environment->project->public_id, 'environment' => $environment->public_id, 'deployment' => $deployment->public_id]),
            environmentUrl: $domain === null ? null : "https://{$domain}",
            description: $deployment->status === DeploymentStatus::Failed ? $deployment->failure_message : null,
            pullRequestNumber: $environment->pr_number,
            deploymentApi: $environment->kind !== EnvironmentKind::Preview,
            provider: $source->provider,
        )));
    }

    public static function state(DeploymentStatus $status): DeploymentReportState
    {
        return match ($status) {
            DeploymentStatus::Queued => DeploymentReportState::Queued,
            DeploymentStatus::Active => DeploymentReportState::Success,
            DeploymentStatus::Failed => DeploymentReportState::Failure,
            DeploymentStatus::Cancelled, DeploymentStatus::Superseded => DeploymentReportState::Cancelled,
            default => DeploymentReportState::InProgress,
        };
    }
}

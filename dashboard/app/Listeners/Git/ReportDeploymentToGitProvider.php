<?php

declare(strict_types=1);

namespace App\Listeners\Git;

use App\Data\Git\DeploymentStatusReport;
use App\Enums\Git\DeploymentReportState;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Models\Deployment;
use App\Services\Git\Feedback\DeploymentFeedback;

/**
 * Sends a deployment's status changes to its git provider (DeploymentFeedback
 * decides what is reported). Reporting runs in a job and never affects the
 * deployment.
 */
final readonly class ReportDeploymentToGitProvider
{
    public function __construct(
        private DeploymentFeedback $feedback,
    ) {}

    public function handle(DeploymentStatusChanged $event): void
    {
        $state = DeploymentFeedback::change($event->from, $event->to);

        if (! $state instanceof DeploymentReportState) {
            return;
        }

        $deployment = Deployment::query()->find($event->deploymentId);
        $report = $deployment instanceof Deployment ? $this->feedback->report($deployment, $state) : null;

        if ($report instanceof DeploymentStatusReport) {
            dispatch(new ReportDeploymentStatusJob($report));
        }
    }
}

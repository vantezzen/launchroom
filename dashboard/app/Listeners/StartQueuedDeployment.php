<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Data\Git\DeploymentStatusReport;
use App\Enums\DeploymentStatus;
use App\Enums\Git\DeploymentReportState;
use App\Events\DeploymentQueued;
use App\Jobs\AdvanceDeploymentJob;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Models\Deployment;
use App\Services\Git\Feedback\DeploymentFeedback;

/**
 * A new deployment starts (docs/04 §6), and the git provider learns it is
 * queued (docs/06 §2.4) before any later status reaches it.
 */
final readonly class StartQueuedDeployment
{
    public function __construct(
        private DeploymentFeedback $feedback,
    ) {}

    public function handle(DeploymentQueued $event): void
    {
        $deployment = Deployment::query()->find($event->deploymentId);

        if (! $deployment instanceof Deployment || $deployment->status !== DeploymentStatus::Queued) {
            return;
        }

        $report = $this->feedback->report($deployment, DeploymentReportState::Queued);

        if ($report instanceof DeploymentStatusReport) {
            dispatch(new ReportDeploymentStatusJob($report));
        }

        dispatch(new AdvanceDeploymentJob($deployment->id));
    }
}

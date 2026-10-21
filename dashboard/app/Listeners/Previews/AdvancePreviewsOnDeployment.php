<?php

declare(strict_types=1);

namespace App\Listeners\Previews;

use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Previews\AdvancePreviewsJob;
use App\Jobs\Previews\ReportPreviewToGitHubJob;
use App\Models\Deployment;
use App\Models\Previews\Preview;

/**
 * A preview's deployment changed status: its databases may now run (the
 * apps can follow), and GitHub's preview deployment status may change.
 */
final class AdvancePreviewsOnDeployment
{
    public function handle(DeploymentStatusChanged $event): void
    {
        if (! in_array($event->to, [DeploymentStatus::Active, DeploymentStatus::Failed, DeploymentStatus::Building, DeploymentStatus::Queued], true)) {
            return;
        }

        $deployment = Deployment::query()->whereKey($event->deploymentId)->with('environment')->first(['id', 'environment_id', 'team_id']);

        if (! $deployment instanceof Deployment || $deployment->environment->kind !== EnvironmentKind::Preview) {
            return;
        }

        $preview = Preview::query()->where('environment_id', $deployment->environment_id)->first(['id', 'team_id']);

        if (! $preview instanceof Preview) {
            return;
        }

        if ($event->to === DeploymentStatus::Active) {
            dispatch(new AdvancePreviewsJob($preview->team_id));
        }

        dispatch(new ReportPreviewToGitHubJob($preview->id));
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners\Projects;

use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ProjectIconMode;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Projects\FetchProjectIconJob;
use App\Models\Deployment;

/**
 * A production deployment went live: look up the project's favicon again
 * (automatic icons only, at most once a day per project).
 */
final class RefreshProjectIconOnDeploy
{
    public function handle(DeploymentStatusChanged $event): void
    {
        if ($event->to !== DeploymentStatus::Active) {
            return;
        }

        $deployment = Deployment::query()->whereKey($event->deploymentId)->with('environment.project')->first(['id', 'environment_id']);
        $environment = $deployment?->environment;
        $project = $environment?->project;

        if ($environment === null || $project === null || $environment->kind !== EnvironmentKind::Production || $project->icon_mode !== ProjectIconMode::Automatic) {
            return;
        }

        if ($project->icon_checked_at !== null && $project->icon_checked_at->isAfter(now()->subDay())) {
            return;
        }

        dispatch(new FetchProjectIconJob($project->id));
    }
}

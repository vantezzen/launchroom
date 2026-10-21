<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Variable;

/**
 * Nested route parameters are bound one by one (team-scoped, docs/09 R7);
 * these checks make sure they also belong together, otherwise 404.
 */
trait ScopesProjectRoutes
{
    protected function scopeEnvironment(Project $project, Environment $environment): void
    {
        abort_unless($environment->project_id === $project->id, 404);
    }

    protected function scopeInstance(Project $project, Environment $environment, Service $service): ServiceInstance
    {
        $this->scopeEnvironment($project, $environment);
        abort_unless($service->project_id === $project->id, 404);

        return ServiceInstance::query()
            ->current()
            ->where('environment_id', $environment->id)
            ->where('service_id', $service->id)
            ->firstOrFail();
    }

    protected function scopeDeployment(Project $project, Environment $environment, Deployment $deployment): void
    {
        $this->scopeEnvironment($project, $environment);
        abort_unless($deployment->environment_id === $environment->id, 404);
    }

    protected function scopeVariable(Project $project, Environment $environment, Variable $variable): void
    {
        $this->scopeEnvironment($project, $environment);
        abort_unless($variable->project_id === $project->id, 404);
    }
}

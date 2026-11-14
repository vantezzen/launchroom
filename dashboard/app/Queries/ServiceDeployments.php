<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\DeploymentStepData;
use App\Data\Deployments\DeploymentSummaryData;
use App\Data\Deployments\ServiceDeploymentCurrentData;
use App\Data\Deployments\ServiceDeploymentsData;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\ServiceInstance;
use App\Services\Deployments\ReleaseStep;
use Illuminate\Support\Facades\Gate;

/**
 * Service panel → Deployments (docs/08 §3.4) for the viewer: the active
 * deployment, the newest rows and the expanded first row (the deployment
 * in progress, else the live one, else the newest: a failed or cancelled
 * first deploy stays readable) with its recorded steps. Authorize `view`
 * on the instance first.
 */
final readonly class ServiceDeployments
{
    public const int HISTORY = 50;

    public function __construct(private DeploymentSummaries $summaries) {}

    public function for(ServiceInstance $instance): ServiceDeploymentsData
    {
        ['active' => $active, 'rows' => $rows] = $this->summaries->forInstance($instance, self::HISTORY);
        $running = [DeploymentStatus::Queued, ...DeploymentStatus::inFlight()];
        $current = array_values(array_filter($rows, static fn (DeploymentSummaryData $row): bool => in_array($row->status, $running, true)))[0] ?? $active ?? $rows[0] ?? null;
        $deployment = $current === null ? null : Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->where('public_id', $current->id)
            ->first();
        $steps = $deployment === null ? [] : array_values($deployment->steps()->orderBy('id')->get()
            ->map(static fn (DeploymentStep $step): DeploymentStepData => DeploymentStepData::fromStep($step))
            ->all());
        $instance->loadMissing('environment');

        return new ServiceDeploymentsData(
            active: $active,
            deployments: $rows,
            current: $current === null ? null : new ServiceDeploymentCurrentData(
                id: $current->id,
                steps: $steps,
                expectsRelease: $deployment !== null && ReleaseStep::expected($deployment),
            ),
            canDeploy: $instance->stopped_at === null && Gate::allows('deploy', $instance->environment),
        );
    }
}

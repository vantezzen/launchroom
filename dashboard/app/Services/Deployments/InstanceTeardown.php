<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Contracts\BuildOrchestrator;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;

/**
 * Stops a service instance in its environment (removed or disabled by a
 * Deploy): drops its workloads from the desired state, cancels unfinished
 * deployments and retires the active one. Volumes (data) are kept.
 */
final readonly class InstanceTeardown
{
    public function __construct(
        private BuildOrchestrator $builds,
    ) {}

    /**
     * @return list<int> servers whose desired state changed
     */
    public function handle(ServiceInstance $instance): array
    {
        $deployments = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight(), DeploymentStatus::Active]))
            ->lockForUpdate()
            ->get();

        foreach ($deployments as $deployment) {
            if ($deployment->status === DeploymentStatus::Building) {
                $this->builds->cancel($deployment);
            }

            $deployment->transitionTo($deployment->status === DeploymentStatus::Active ? DeploymentStatus::Superseded : DeploymentStatus::Cancelled);
        }

        /** @var list<int> $servers */
        $servers = Placement::query()->where('service_instance_id', $instance->id)->pluck('server_id')->unique()->values()->all();

        Placement::query()->where('service_instance_id', $instance->id)->delete();
        $instance->forceFill(['active_deployment_id' => null])->save();

        return $servers;
    }
}

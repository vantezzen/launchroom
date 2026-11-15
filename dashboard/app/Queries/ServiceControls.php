<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Canvas\ServiceControlsData;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\ServiceKind;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The service panel header's actions for one viewer (D68, docs/08 §13).
 */
final readonly class ServiceControls
{
    public function __construct(
        private StagedForService $staged,
    ) {}

    /**
     * @param  EnvironmentSpec|null  $current  the environment's current spec, when the caller already built it
     */
    public function for(ServiceInstance $instance, User $user, ?EnvironmentSpec $current = null): ServiceControlsData
    {
        $instance->loadMissing(['service', 'environment']);
        $service = $instance->service;
        $stoppedBy = $instance->stopped_by_id === null ? null : User::query()->whereKey($instance->stopped_by_id)->value('name');

        return new ServiceControlsData(
            canControl: $instance->removed_at === null && Gate::forUser($user)->allows('deploy', $instance->environment),
            running: $instance->active_deployment_id !== null,
            stopped: $instance->stopped_at !== null,
            stoppedAt: $instance->stopped_at,
            stoppedBy: is_string($stoppedBy) ? $stoppedBy : null,
            versioned: $service->kind->deploysNewVersions(),
            staged: $this->staged->has($instance, $current),
            deploying: Deployment::query()->where('service_instance_id', $instance->id)->unfinished()->exists(),
            processes: $service->kind === ServiceKind::App ? ServiceProcesses::of($instance)->count() : 0,
            dependents: Connection::query()->where('to_service_id', $service->id)->count(),
        );
    }
}

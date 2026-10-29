<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Specs\EnvironmentSpec;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceKind;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\ServiceProcesses;
use App\Support\Services\StagedForService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Data;

/**
 * The service panel header's actions (D68, docs/08 §13): Deploy, Restart,
 * Stop and Start, and what the dialogs need to say.
 */
final class ServiceControlsData extends Data
{
    public function __construct(
        /** The environment's `deploy` permission (every action needs it). */
        public bool $canControl,
        /** It has a live deployment (Restart and Stop make sense). */
        public bool $running,
        public bool $stopped,
        public ?CarbonInterface $stoppedAt,
        public ?string $stoppedBy,
        /** Apps and image services deploy a new version; databases, workers and crons restart. */
        public bool $versioned,
        /** It has staged changes: Deploy is the environment's "Deploy changes". */
        public bool $staged,
        /** A deployment of it hasn't finished (Restart waits for it). */
        public bool $deploying,
        /** Its workers and crons (apps): they stop, start and restart with it when chosen. */
        public int $processes,
        /** Services connected to it (databases): they lose it while it is stopped. */
        public int $dependents,
    ) {}

    public static function for(ServiceInstance $instance, User $user, StagedForService $staged, ?EnvironmentSpec $current = null): self
    {
        $instance->loadMissing(['service', 'environment']);
        $environment = $instance->environment;
        $service = $instance->service;
        $stoppedBy = $instance->stopped_by_id === null ? null : User::query()->whereKey($instance->stopped_by_id)->value('name');

        return new self(
            canControl: $instance->removed_at === null && Gate::forUser($user)->allows('deploy', $environment),
            running: $instance->active_deployment_id !== null,
            stopped: $instance->stopped_at !== null,
            stoppedAt: $instance->stopped_at,
            stoppedBy: is_string($stoppedBy) ? $stoppedBy : null,
            versioned: in_array($service->kind, [ServiceKind::App, ServiceKind::Image], true),
            staged: $staged->has($instance, $current),
            deploying: Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->whereIn('status', DeploymentStatus::values([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()]))
                ->exists(),
            processes: $service->kind === ServiceKind::App ? ServiceProcesses::of($instance)->count() : 0,
            dependents: Connection::query()->where('to_service_id', $service->id)->count(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Data\Protocol\V1\Tasks\SelfUpdateSpec;
use App\Enums\DaemonTaskPurpose;
use App\Enums\Updates\UpdateScope;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Tasks\DaemonTasks;

/**
 * Creates self_update tasks (docs/11 §4). The daemon downloads the signed
 * release bundle of `version` itself and checks `image` against it; the
 * dashboard only picks the version. The attempt may be completed by the new
 * daemon process after a restart, possibly after the lease expired and the
 * task was leased again (a re-leased attempt of a finished update completes
 * at once with the recorded outcome).
 */
final readonly class SelfUpdateTasks
{
    public function __construct(
        private DaemonTasks $tasks,
    ) {}

    public function create(Server $server, string $version, string $image, UpdateScope $scope): DaemonTask
    {
        $controlPlane = $scope === UpdateScope::ControlPlane;

        return $this->tasks->create(
            $server->team_id,
            new SelfUpdateSpec($version, $image, $controlPlane ? $scope->value : null),
            $controlPlane ? 45 * 60 : 20 * 60,
            pin: $server,
            purpose: $controlPlane ? DaemonTaskPurpose::ControlPlaneUpdate : DaemonTaskPurpose::FleetUpdate,
        );
    }
}

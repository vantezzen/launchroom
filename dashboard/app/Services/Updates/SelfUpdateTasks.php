<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\DaemonTaskType;
use App\Enums\Updates\UpdateScope;
use App\Models\DaemonTask;
use App\Models\Server;

/**
 * Creates self_update tasks (protocol/schema/v1/task.json, docs/11 §4). The
 * daemon downloads the signed release bundle of `version` itself and checks
 * `image` against it; the dashboard only picks the version.
 *
 * The attempt may be completed by the new daemon process after a restart,
 * possibly after the lease expired and the task was leased again, so a few
 * attempts are allowed (a re-leased attempt of a finished update completes
 * at once with the recorded outcome).
 */
final class SelfUpdateTasks
{
    public const string PURPOSE_CONTROL_PLANE = 'cp_update';

    public const string PURPOSE_FLEET = 'fleet_update';

    public function create(Server $server, string $version, string $image, UpdateScope $scope): DaemonTask
    {
        $spec = ['version' => $version, 'image' => $image];

        if ($scope === UpdateScope::ControlPlane) {
            $spec['scope'] = $scope->value;
        }

        return DaemonTask::query()->create([
            'team_id' => $server->team_id,
            'server_id' => $server->id,
            'pinned_to_server' => true,
            'type' => DaemonTaskType::SelfUpdate,
            'spec' => (object) $spec,
            'max_attempts' => 3,
            'timeout_seconds' => $scope === UpdateScope::ControlPlane ? 45 * 60 : 20 * 60,
            'purpose' => $scope === UpdateScope::ControlPlane ? self::PURPOSE_CONTROL_PLANE : self::PURPOSE_FLEET,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\LogSourceKind;
use App\Events\Broadcasts\DeploymentLogsAppended;
use App\Events\LogChunksStored;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Placement;

/**
 * Maps freshly stored log chunks to the deployments they belong to (task,
 * deployment and workload sources) and nudges each deployment's channel once.
 */
final class BroadcastDeploymentLogs
{
    public function handle(LogChunksStored $event): void
    {
        $ids = [];

        foreach ([LogSourceKind::Task, LogSourceKind::Deployment, LogSourceKind::Workload] as $kind) {
            $sources = array_values(array_unique(array_map(
                static fn (array $source): string => $source['id'],
                array_filter($event->sources, static fn (array $source): bool => $source['kind'] === $kind),
            )));

            if ($sources === []) {
                continue;
            }

            $found = match ($kind) {
                LogSourceKind::Task => DaemonTask::query()->where('team_id', $event->teamId)->whereIn('public_id', $sources)->whereNotNull('deployment_id')->pluck('deployment_id')->all(),
                LogSourceKind::Workload => Placement::query()->where('team_id', $event->teamId)->whereIn('workload_id', $sources)->pluck('deployment_id')->all(),
                LogSourceKind::Deployment => Deployment::query()->where('team_id', $event->teamId)->whereIn('public_id', $sources)->pluck('id')->all(),
            };

            foreach ($found as $id) {
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
        }

        if ($ids === []) {
            return;
        }

        $publicIds = Deployment::query()->whereIn('id', array_unique($ids))->pluck('public_id')->all();

        foreach ($publicIds as $publicId) {
            if (is_string($publicId)) {
                broadcast(new DeploymentLogsAppended($publicId));
            }
        }
    }
}

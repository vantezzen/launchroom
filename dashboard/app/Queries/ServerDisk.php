<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Protocol\V1\CleanupResult;
use App\Data\Servers\CleanupTaskData;
use App\Data\Servers\DiskVolumeData;
use App\Data\Servers\Host\DiskBreakdownData;
use App\Data\Servers\Host\HostHealthData;
use App\Data\Servers\Host\VolumeUsageData;
use App\Data\Servers\ServerDiskData;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\Volume;
use App\Models\VolumeRetirement;

/**
 * Props of the server page's Disk section (G18): the latest reported disk
 * usage and breakdown, volume ids resolved to their services (retired ones
 * through their retirement record), and the last "Free space now" of the
 * past day.
 */
final readonly class ServerDisk
{
    public function for(Server $server, bool $canUpdate): ServerDiskData
    {
        $health = $server->host_status->health ?? new HostHealthData;
        $fs = $health->dockerDisk ?? $health->dataDisk;
        $breakdown = $health->disk;
        $task = DaemonTask::query()
            ->where('server_id', $server->id)
            ->where('type', DaemonTaskType::Cleanup->value)
            ->where('created_at', '>', now()->subDay())
            ->latest('id')
            ->first();
        $result = $task?->result?->cleanup;
        $unavailable = match (true) {
            ! $canUpdate => null,
            $server->status !== ServerStatus::Online => "{$server->name} isn’t online.",
            ! $server->supportsDaemonFeature(DaemonFeature::CleanupTask) => 'Update launchroom on this server to clean up on request.',
            default => null,
        };

        return new ServerDiskData(
            totalMb: $fs?->totalMb,
            freeMb: $fs?->freeMb,
            inodesFreePercent: $fs?->inodesFreePercent(),
            imagesMb: $health->docker?->imagesMb,
            imagesReclaimableMb: $health->docker?->imagesReclaimableMb,
            buildCacheMb: $health->buildCacheMb,
            walBacklogMb: $breakdown?->walBacklogMb,
            registryMb: $breakdown?->registryMb,
            volumes: $breakdown instanceof DiskBreakdownData ? $this->volumes($server, $breakdown) : [],
            measuredAt: $breakdown?->measuredAt,
            lastCleanup: $health->cleanup,
            cleanupTask: $task instanceof DaemonTask ? new CleanupTaskData(
                status: $task->status,
                requestedAt: $task->created_at,
                finishedAt: $task->finished_at,
                removedImages: $result instanceof CleanupResult ? $result->removedImages : null,
                removedContainers: $result instanceof CleanupResult ? $result->removedContainers : null,
                reclaimedMb: $result instanceof CleanupResult ? $result->reclaimedMb : null,
                error: $task->error !== null ? mb_substr($task->error, 0, 300) : null,
            ) : null,
            canFreeSpace: $canUpdate && $unavailable === null,
            freeSpaceUnavailable: $unavailable,
        );
    }

    /**
     * @return list<DiskVolumeData>
     */
    private function volumes(Server $server, DiskBreakdownData $breakdown): array
    {
        $ids = array_map(static fn (VolumeUsageData $volume): string => $volume->id, $breakdown->volumes);
        $live = Volume::query()
            ->whereIn('public_id', $ids)
            ->where('team_id', $server->team_id)
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->get()
            ->keyBy('public_id');
        $retired = VolumeRetirement::query()
            ->where('server_id', $server->id)
            ->whereIn('volume_public_id', $ids)
            ->get()
            ->keyBy('volume_public_id');
        $out = [];

        foreach ($breakdown->volumes as $usage) {
            $volume = $live->get($usage->id);
            $retirement = $retired->get($usage->id);

            if ($volume instanceof Volume) {
                $instance = $volume->serviceInstance;
                $out[] = new DiskVolumeData($usage->id, $instance->service->name, $instance->environment->project->name.' / '.$instance->environment->name, $volume->mount_path, $usage->sizeMb, $usage->retired);
            } elseif ($retirement instanceof VolumeRetirement) {
                $out[] = new DiskVolumeData($usage->id, $retirement->label, null, $retirement->mount_path, $usage->sizeMb, true);
            } else {
                $out[] = new DiskVolumeData($usage->id, $usage->id, null, null, $usage->sizeMb, $usage->retired);
            }
        }

        return $out;
    }
}

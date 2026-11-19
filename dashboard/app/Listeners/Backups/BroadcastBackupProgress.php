<?php

declare(strict_types=1);

namespace App\Listeners\Backups;

use App\Broadcasting\ServiceActivityTopic;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\LogSourceKind;
use App\Events\Broadcasts\BackupsChanged;
use App\Events\Broadcasts\ServiceActivityChanged;
use App\Events\LogChunksStored;
use App\Models\Backups\BackupRun;
use App\Models\DaemonTask;
use Illuminate\Support\Facades\Cache;

/**
 * A backup task logged something (launchroomd's progress lines arrive every
 * few seconds while it streams): the Backups page and the service's Backups
 * tab refetch. At most one nudge per team (and per service) every 2 seconds.
 */
final class BroadcastBackupProgress
{
    private const int THROTTLE_SECONDS = 2;

    public function handle(LogChunksStored $event): void
    {
        $tasks = array_values(array_unique(array_map(
            static fn (array $source): string => $source['id'],
            array_filter($event->sources, static fn (array $source): bool => $source['kind'] === LogSourceKind::Task),
        )));

        if ($tasks === []) {
            return;
        }

        $runs = BackupRun::query()
            ->whereIn('daemon_task_id', DaemonTask::query()->whereIn('public_id', $tasks)->select('id'))
            ->whereIn('status', BackupRunStatus::nonTerminal())
            ->with(['serviceInstance.environment', 'serviceInstance.service'])
            ->get();

        foreach ($runs->pluck('team_id')->unique() as $teamId) {
            if (is_int($teamId) && Cache::add('launchroom:backups:progress-nudge:'.$teamId, true, self::THROTTLE_SECONDS)) {
                BackupsChanged::about($teamId);
            }
        }

        foreach ($runs->unique('service_instance_id') as $run) {
            if ($run->service_instance_id !== null && Cache::add('launchroom:backups:progress-nudge:i'.$run->service_instance_id, true, self::THROTTLE_SECONDS)) {
                ServiceActivityChanged::about($run->serviceInstance, ServiceActivityTopic::Backups);
            }
        }
    }
}

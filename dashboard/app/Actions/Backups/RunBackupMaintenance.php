<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Models\Backups\BackupSchedule;
use App\Models\Server;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;
use App\Services\Backups\SnapshotTags;
use Throwable;

/**
 * Retention (docs/14 §3 "Maintenance"), once a day per schedule:
 *
 * - databases: a `backup_maintenance` forget task with the schedule's
 *   retention, restricted to that database's snapshots (tags
 *   kind/environment/service), on the server that runs it (or any online
 *   server of the team); then one `prune` per repository per day;
 * - volumes: the same per volume (tags kind/environment/service/volume);
 * - control plane: `restic forget` per part (database, keys) and `prune`,
 *   by the dashboard itself.
 */
final readonly class RunBackupMaintenance
{
    public function __construct(
        private BackupTasks $tasks,
        private DestinationSecrets $secrets,
        private ResticCli $restic,
    ) {}

    public function handle(BackupSchedule $schedule): void
    {
        $schedule->loadMissing(['destination', 'serviceInstance.service', 'serviceInstance.environment']);
        $schedule->forceFill(['last_maintenance_at' => now()])->save();

        if ($schedule->subject === BackupSubject::ControlPlane) {
            $this->controlPlane($schedule);

            return;
        }

        $instance = $schedule->serviceInstance;

        if ($instance === null || ! $schedule->retention->keepsSomething()) {
            return;
        }

        $server = DatabaseWorkloads::servingPlacement($instance)->server
            ?? Server::query()->where('team_id', $instance->team_id)->where('status', ServerStatus::Online->value)->orderBy('id')->first();

        if (! $server instanceof Server) {
            return;
        }

        if ($schedule->subject === BackupSubject::Volume) {
            // Per volume: one group per volume keeps each volume's dailies.
            foreach (BackupSubjects::placedVolumes($instance) as $volume) {
                $this->tasks->maintenance($schedule->destination, $server, $instance->team_id, 'forget', $schedule->retention, SnapshotTags::volumeFilter($instance, $volume));
            }
        } else {
            $this->tasks->maintenance($schedule->destination, $server, $instance->team_id, 'forget', $schedule->retention, SnapshotTags::databaseFilter($instance));
        }

        // One prune per repository per day (exclusive lock, docs/14 §3).
        $lock = cache()->lock('launchroom:backups:prune:'.$schedule->destination_id, 86400);

        if ($lock->get()) {
            $this->tasks->maintenance($schedule->destination, $server, $instance->team_id, 'prune');
        }
    }

    private function controlPlane(BackupSchedule $schedule): void
    {
        $destination = $schedule->destination;

        if (! $schedule->retention->keepsSomething() || ! $this->restic->available()) {
            return;
        }

        try {
            $target = ResticTarget::for($destination, $this->secrets->reveal($destination));

            // Per part, so "latest per day" picks the database dump and the
            // key bundle of the same run (runs never overlap).
            foreach (['database', 'keys'] as $part) {
                $this->restic->run($target, [
                    'forget', '--tag', 'kind:control-plane,part:'.$part, '--group-by', 'host', ...$schedule->retention->resticFlags(),
                ], 3600);
            }

            $this->restic->run($target, ['prune'], config()->integer('launchroom.backups.task_timeout_seconds'));
        } catch (Throwable $e) {
            report($e);
        }
    }
}

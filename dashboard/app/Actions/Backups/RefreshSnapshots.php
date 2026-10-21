<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\BackupSubject;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\BackupTasks;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\SnapshotCache;
use App\Services\Backups\SnapshotTags;

/**
 * Lists a database's or a service's volume snapshots from the repository (docs/14 §2: restore UIs
 * are built from `restic snapshots --json` filtered by tags). A
 * `backup_snapshots` task runs on the database's server (or any online
 * server of the team); the result is cached briefly ({@see SnapshotCache}).
 */
final readonly class RefreshSnapshots
{
    public function __construct(
        private BackupTasks $tasks,
        private SnapshotCache $cache,
    ) {}

    public function handle(ServiceInstance $instance): DaemonTask
    {
        $destination = BackupSchedule::query()->where('service_instance_id', $instance->id)->first()?->destination()->first()
            ?? BackupDestination::defaultFor($instance->team_id);

        if (! $destination instanceof BackupDestination) {
            throw DomainRuleViolation::on('destination', 'Add a backup destination in Settings → Backups first.');
        }

        $server = DatabaseWorkloads::servingPlacement($instance)->server
            ?? Server::query()->where('team_id', $instance->team_id)->where('status', ServerStatus::Online->value)->orderByDesc('is_control_plane')->orderBy('id')->first();

        if (! $server instanceof Server) {
            throw DomainRuleViolation::on('server', 'No server is online to read the backup repository.');
        }

        $tags = BackupSubjects::for($instance) === BackupSubject::Volume ? SnapshotTags::volumeFilter($instance) : SnapshotTags::databaseFilter($instance);
        $task = $this->tasks->snapshots($destination, $server, $instance->team_id, $tags);
        $this->cache->pending($instance, $task);

        return $task;
    }
}

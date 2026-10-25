<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Transfers\Inputs\StartExportInput;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\DaemonFeature;
use App\Enums\ServerStatus;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ExportSource;
use App\Enums\Transfers\TransferPurpose;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\SnapshotCache;
use App\Services\Backups\SnapshotTags;
use App\Services\Transfers\ImportTarget;
use App\Services\Transfers\TransferStore;
use App\Services\Transfers\TransferTasks;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Starts an export (docs/14 §12.5): a fresh dump or tarball of the data as it
 * is now, or a stored backup, decrypted. launchroomd uploads it in chunks
 * into a download transfer; {@see SyncTransferTasks} checks it and makes it
 * ready. The controller has authorized the user; decrypted data leaves the
 * platform, so the start is audited.
 */
final readonly class StartExport
{
    public function __construct(
        private TransferStore $store,
        private TransferTasks $tasks,
        private SnapshotCache $snapshots,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, StartExportInput $input, User $user, AuditActor $actor): DataExportRun
    {
        $instance->loadMissing(['service', 'environment', 'volumes']);
        $target = ImportTarget::for($instance);

        if (! $target->subject instanceof DataSubject) {
            throw DomainRuleViolation::on('service', 'Only databases and services with volumes can be downloaded.');
        }

        $source = $input->snapshot === null ? ExportSource::Live : ExportSource::Snapshot;
        [$snapshot, $snapshotVolume] = $source === ExportSource::Snapshot ? $this->snapshot($instance, (string) $input->snapshot, $target->subject) : [null, null];
        $volume = null;
        $destination = null;

        if ($target->subject === DataSubject::Volume) {
            $volume = $source === ExportSource::Live
                ? $target->volume($input->volume, 'download files')
                : $instance->volumes->firstWhere('public_id', $snapshotVolume ?? $input->volume);
        }

        if ($source === ExportSource::Snapshot) {
            $destination = BackupSchedule::query()->where('service_instance_id', $instance->id)->first()?->destination()->first()
                ?? BackupDestination::defaultFor($instance->team_id);

            if (! $destination instanceof BackupDestination) {
                throw DomainRuleViolation::on('destination', 'This service has no backup destination.');
            }
        }

        $server = $this->server($instance, $target, $source, $volume);

        return DB::transaction(function () use ($instance, $target, $source, $snapshot, $volume, $destination, $server, $user, $actor): DataExportRun {
            ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->first();

            if (DataExportRun::query()->where('service_instance_id', $instance->id)->where('status', ExportRunStatus::Preparing->value)->exists()) {
                throw DomainRuleViolation::conflict('A download of this service is already being prepared.');
            }

            $purpose = $target->subject === DataSubject::Volume ? TransferPurpose::VolumeExport : TransferPurpose::DatabaseExport;
            $fileName = $target->exportFileName();
            $transfer = new DataTransfer([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'volume_id' => $volume?->id,
                'direction' => $purpose->direction(),
                'purpose' => $purpose,
                'file_name' => $fileName,
                'size_bytes' => 0,
                'chunk_bytes' => config()->integer('launchroom.transfers.chunk_bytes'),
                'chunk_count' => 0,
                'created_by' => $user->id,
                'expires_at' => now()->addSeconds(config()->integer('launchroom.transfers.task_timeout_seconds'))->addMinutes(config()->integer('launchroom.transfers.download_ttl_minutes')),
            ]);
            $transfer->public_id = PublicId::generate(DataTransfer::publicIdPrefix());
            $this->store->initialise($transfer);
            $transfer->save();

            $task = $target->subject === DataSubject::Volume
                ? $this->tasks->exportVolume($server, $instance->team_id, $source === ExportSource::Live ? $volume?->public_id : null, $volume?->backup_consistency->value, $snapshot, $destination, $transfer)
                : $this->tasks->exportDatabase($server, $instance->team_id, $target->engine(), $source === ExportSource::Live ? $this->placement($instance)->workload_id : null, $snapshot, $destination, $transfer);

            $run = new DataExportRun([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'subject' => $target->subject,
                'volume_id' => $volume?->id,
                'source' => $source,
                'snapshot_id' => $snapshot,
                'transfer_id' => $transfer->id,
                'actor_id' => $user->id,
                'file_name' => $fileName,
            ]);
            $run->daemon_task_id = $task->id;
            $run->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DataExportStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter([
                    'export' => $run->public_id,
                    'environment' => $instance->environment->public_id,
                    'source' => $source->value,
                    'snapshot' => $snapshot === null ? null : substr($snapshot, 0, 16),
                    'volume' => $volume?->public_id,
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $run;
        });
    }

    private function placement(ServiceInstance $instance): Placement
    {
        $placement = DatabaseWorkloads::servingPlacement($instance);

        if (! $placement instanceof Placement) {
            throw DomainRuleViolation::on('service', "{$instance->service->name} isn’t running. Deploy it first, or download a backup.");
        }

        return $placement;
    }

    /**
     * The server that reads the data: the database's or the volume's; a
     * backup can be read by any online server of the team that can export.
     */
    private function server(ServiceInstance $instance, ImportTarget $target, ExportSource $source, ?Volume $volume): Server
    {
        $preferred = $target->subject === DataSubject::Database
            ? ($source === ExportSource::Live ? $this->placement($instance)->server : DatabaseWorkloads::servingPlacement($instance)?->server)
            : $volume?->server()->first();

        $usable = static fn (Server $server): bool => $server->status === ServerStatus::Online && $server->supportsDaemonFeature(DaemonFeature::DataTransfer);

        if ($source === ExportSource::Live) {
            if (! $preferred instanceof Server) {
                throw DomainRuleViolation::on('volume', 'This volume is created on the next deploy. Deploy the service first.');
            }

            if ($usable($preferred)) {
                return $preferred;
            }

            throw DomainRuleViolation::on('server', $preferred->status === ServerStatus::Online
                ? "Update {$preferred->name} in Settings → Updates to download data."
                : "{$preferred->name} is offline. Try again once it is back.");
        }

        if ($preferred instanceof Server && $usable($preferred)) {
            return $preferred;
        }

        $any = Server::query()->where('team_id', $instance->team_id)->where('status', ServerStatus::Online->value)->orderBy('id')->get()->first($usable);

        if ($any === null) {
            throw DomainRuleViolation::on('server', 'No online server can read the backup. Update your servers in Settings → Updates.');
        }

        return $any;
    }

    /**
     * The snapshot must be one of this service's backups (a recorded run or
     * the repository listing), like a restore's.
     *
     * @return array{string, string|null} full snapshot id and, for volumes, the volume's public id
     */
    private function snapshot(ServiceInstance $instance, string $prefix, DataSubject $subject): array
    {
        $kind = $subject === DataSubject::Volume ? BackupSubject::Volume : BackupSubject::Database;
        $run = BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('subject', $kind->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->where('snapshot_id', 'like', $prefix.'%')
            ->with('volume')
            ->first();

        if ($run instanceof BackupRun && $run->snapshot_id !== null) {
            return [$run->snapshot_id, $run->volume?->public_id];
        }

        foreach ($this->snapshots->forInstance($instance)['snapshots'] ?? [] as $snapshot) {
            if (str_starts_with($snapshot['id'], $prefix) && SnapshotTags::value($snapshot['tags'], 'kind') === $kind->tag()) {
                return [$snapshot['id'], SnapshotTags::value($snapshot['tags'], 'volume')];
            }
        }

        throw DomainRuleViolation::on('snapshot', 'This snapshot is not a backup of this service. Refresh the snapshot list and pick one from it.');
    }
}

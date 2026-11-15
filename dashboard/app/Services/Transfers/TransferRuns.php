<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportSource;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\ImportSourceKind;
use App\Enums\Transfers\TransferPurpose;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use App\Models\Volume;
use App\Support\PublicId;
use Carbon\CarbonInterface;

/**
 * Creates export transfers and export/import runs with their invariants
 * (docs/14 §12). Used by the user-facing StartExport/StartImport, which
 * check the request, refuse while another import or restore runs and
 * audit, and by server moves (docs/14 §13.2), which do neither on purpose:
 * a move holds the service's writers itself, runs one stateful service at
 * a time, and audits each moved service once (`server_move.item_finished`).
 */
final readonly class TransferRuns
{
    public function __construct(
        private TransferStore $store,
    ) {}

    /**
     * A transfer launchroomd uploads an export into.
     */
    public function outgoing(ServiceInstance $instance, ?Volume $volume, TransferPurpose $purpose, string $fileName, ?User $createdBy, CarbonInterface $expiresAt): DataTransfer
    {
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
            'created_by' => $createdBy?->id,
            'expires_at' => $expiresAt,
        ]);
        $transfer->public_id = PublicId::generate(DataTransfer::publicIdPrefix());
        $this->store->initialise($transfer);
        $transfer->save();

        return $transfer;
    }

    public function export(ServiceInstance $instance, DataSubject $subject, ?Volume $volume, ExportSource $source, ?string $snapshot, DataTransfer $transfer, int $taskId, ?User $user): DataExportRun
    {
        $run = new DataExportRun([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'subject' => $subject,
            'volume_id' => $volume?->id,
            'source' => $source,
            'snapshot_id' => $snapshot,
            'transfer_id' => $transfer->id,
            'actor_id' => $user?->id,
            'file_name' => $transfer->file_name,
        ]);
        $run->daemon_task_id = $taskId;
        $run->save();

        return $run;
    }

    /**
     * An import run waiting for its safety snapshot (AdvanceImports).
     */
    public function import(
        ServiceInstance $instance,
        DataSubject $subject,
        ?Volume $volume,
        ?DataTransfer $transfer,
        string $label,
        int $serverId,
        bool $replace,
        bool $stopDependents,
        ?User $user,
        int $stripComponents = 0,
        ?int $ownerUid = null,
        ?int $ownerGid = null,
        ?BackupRun $safety = null,
        ?string $sealedSource = null,
        ?string $reservedTaskId = null,
    ): DataImportRun {
        $run = new DataImportRun([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'subject' => $subject,
            'volume_id' => $volume?->id,
            'transfer_id' => $transfer?->id,
            'source_kind' => $transfer instanceof DataTransfer ? ImportSourceKind::File : ImportSourceKind::Url,
            'source_label' => mb_strcut($label, 0, 255),
            'server_id' => $serverId,
            'replace' => $replace,
            'stop_dependents' => $stopDependents,
            'strip_components' => $subject === DataSubject::Volume ? $stripComponents : 0,
            'owner_uid' => $subject === DataSubject::Volume ? $ownerUid : null,
            'owner_gid' => $subject === DataSubject::Volume ? $ownerGid : null,
            'actor_id' => $user?->id,
        ]);
        $run->status = ImportRunStatus::WaitingForSafetyBackup;
        $run->safety_backup_run_id = $safety?->id;
        $run->sealed_source = $sealedSource;
        $run->reserved_task_id = $reservedTaskId;
        $run->save();

        return $run;
    }
}

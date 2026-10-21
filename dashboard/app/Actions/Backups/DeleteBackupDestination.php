<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Removes a destination and its schedules from launchroom. The restic
 * repository and its snapshots stay in the bucket: deleting data in the
 * user's storage is never automatic.
 */
final readonly class DeleteBackupDestination
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(BackupDestination $destination, AuditActor $actor): void
    {
        DB::transaction(function () use ($destination, $actor): void {
            $destination = BackupDestination::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $schedules = BackupSchedule::query()->where('destination_id', $destination->id)->count();

            $destination->delete();

            if ($destination->is_default && $destination->team_id !== null) {
                BackupDestination::query()->where('team_id', $destination->team_id)->orderBy('id')->limit(1)->update(['is_default' => true]);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationDeleted,
                actor: $actor,
                teamId: $destination->team_id,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['schedules' => $schedules],
            ));
        });
    }
}

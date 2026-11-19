<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Events\Broadcasts\BackupsChanged;
use App\Events\NodeStateInvalidated;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\WalArchive;
use App\Services\Holds\Holds;
use Illuminate\Support\Facades\DB;

/**
 * Removes a destination from launchroom (docs/14 §3). The backups stay in
 * the bucket (deleting data in the user's storage is never automatic), and
 * launchroom keeps their history: the row stays as "storage removed"
 * (`removed_at`) with its access keys wiped, and every run, restore,
 * point-in-time archive and verification keeps pointing at it, read-only.
 * The repository password stays (sealed as before): it is what reconnects
 * the backups later, and it can be revealed to read them with plain restic.
 *
 * Its schedules keep their cron and retention: they move to the team's next
 * default destination, or wait (nothing runs, nothing alerts) until storage
 * is added again (DestinationHistory::adoptSchedules). Point-in-time
 * recovery to it stops. Adding the same bucket and folder again reconnects
 * the history with the kept password; only new access keys are needed.
 */
final readonly class DeleteBackupDestination
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(BackupDestination $destination, AuditActor $actor): void
    {
        $servers = DB::transaction(function () use ($destination, $actor): array {
            $destination = BackupDestination::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $schedules = BackupSchedule::query()->where('destination_id', $destination->id)->count();
            $runs = BackupRun::query()->where('destination_id', $destination->id)->count();

            $archived = WalArchive::query()->where('destination_id', $destination->id)->where('enabled', true)->pluck('service_instance_id')->all();
            WalArchive::query()->where('destination_id', $destination->id)->where('enabled', true)
                ->update(['enabled' => false, 'message' => 'Its backup storage was removed.']);

            // Nothing can use it any more: the access keys go. The repository
            // password stays sealed under the row's key to reconnect later.
            $destination->forceFill(['credentials' => '', 'is_default' => false])->save();
            $destination->delete();

            if ($destination->team_id !== null) {
                $next = BackupDestination::query()->where('team_id', $destination->team_id)->orderByDesc('is_default')->orderBy('id')->first();

                if ($next instanceof BackupDestination) {
                    if (! $next->is_default) {
                        $next->forceFill(['is_default' => true])->save();
                    }

                    BackupSchedule::query()->where('destination_id', $destination->id)->update(['destination_id' => $next->id]);
                }
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupDestinationDeleted,
                actor: $actor,
                teamId: $destination->team_id,
                subjectType: 'backup_destination',
                subjectId: $destination->public_id,
                meta: ['schedules' => $schedules, 'runs' => $runs, 'historyKept' => true],
            ));

            return Holds::serverIds(array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $archived)));
        });

        // Their servers stop shipping WAL there.
        NodeStateInvalidated::servers($servers);
        BackupsChanged::about($destination->team_id);
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStatus;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ImportRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Moves\PrepareHandoverJob;
use App\Models\Backups\BackupRun;
use App\Models\Backups\RestoreRun;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Services\Moves\InstanceFreeze;
use Illuminate\Support\Facades\DB;

/**
 * Step 4 of Settings → Move launchroom (docs/14 §13.4): once every app,
 * database and volume runs on the new server, this dashboard stops taking
 * changes (the freeze) and takes its final dump; the new server's installer
 * fetches it and starts the dashboard there. The window is short: the
 * dashboard's own database is usually small, and apps keep serving
 * throughout (they already run on the new server).
 *
 * Refused while something is in flight (a deploy, a build, a backup, a
 * restore, an import or export, another move) and when the new server's
 * installer isn't waiting (it asks every few seconds).
 */
final readonly class StartHandover
{
    /** The installer must have asked within this many seconds. */
    public const int RECEIVER_WINDOW_SECONDS = 90;

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, AuditActor $actor): ControlPlaneMove
    {
        return DB::transaction(function () use ($move, $actor): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if ($move->status !== ControlPlaneMoveStatus::ReadyToHandOver) {
                throw DomainRuleViolation::conflict('Hand over once every service runs on the new server.');
            }

            if ($move->receiver_seen_at === null || $move->receiver_seen_at->lt(now()->subSeconds(self::RECEIVER_WINDOW_SECONDS))) {
                throw DomainRuleViolation::conflict('The new server’s installer isn’t waiting. Run the install command there again (it continues where it stopped), then hand over.');
            }

            $busy = $this->busy($move);

            if ($busy !== []) {
                throw DomainRuleViolation::conflict('Wait for these to finish first: '.implode(', ', $busy).'.');
            }

            InstanceFreeze::freeze("Moving launchroom to a new server ({$move->public_id})");
            $move->frozen_at = now();
            $move->handover = null;
            $move->transitionTo(ControlPlaneMoveStatus::HandingOver);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneHandoverStarted,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['move' => $move->public_id],
            ));

            dispatch(new PrepareHandoverJob($move->id));

            return $move;
        });
    }

    /**
     * What is still running that the final dump would cut in half.
     *
     * @return list<string>
     */
    private function busy(ControlPlaneMove $move): array
    {
        $busy = [];

        if (Deployment::query()->whereIn('status', [DeploymentStatus::Queued->value, ...DeploymentStatus::values(DeploymentStatus::inFlight())])->exists()) {
            $busy[] = 'deployments';
        }

        if (DaemonTask::query()->whereIn('status', [DaemonTaskStatus::Pending->value, DaemonTaskStatus::Leased->value])->whereIn('type', [DaemonTaskType::Build->value, DaemonTaskType::Backup->value, DaemonTaskType::Restore->value, DaemonTaskType::DbImport->value, DaemonTaskType::VolumeImport->value, DaemonTaskType::DbExport->value, DaemonTaskType::VolumeExport->value])->exists()) {
            $busy[] = 'builds, backups or data copies';
        }

        if (BackupRun::query()->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])->exists()) {
            $busy[] = 'backups';
        }

        if (RestoreRun::query()->whereNotIn('status', [RestoreRunStatus::Succeeded->value, RestoreRunStatus::Failed->value])->exists()) {
            $busy[] = 'restores';
        }

        if (DataImportRun::query()->whereIn('status', [ImportRunStatus::WaitingForSafetyBackup->value, ImportRunStatus::Holding->value, ImportRunStatus::Importing->value])->exists()
            || DataExportRun::query()->where('status', ExportRunStatus::Preparing->value)->exists()) {
            $busy[] = 'imports or downloads';
        }

        if (ServerMove::query()->whereIn('status', ServerMoveStatus::values(ServerMoveStatus::open()))->where('id', '!=', $move->server_move_id ?? 0)->exists()) {
            $busy[] = 'another server move';
        }

        return array_values(array_unique($busy));
    }
}

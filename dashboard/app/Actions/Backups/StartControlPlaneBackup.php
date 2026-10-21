<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Backups\RunControlPlaneBackupJob;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Queues a control-plane backup (docs/14 §2): DB dump + sealed key bundle,
 * run by {@see RunControlPlaneBackup} in a queued job on the control plane.
 * Also the "before every upgrade" hook: the upgrade flow runs
 * `php artisan launchroom:backups:control-plane --reason=pre_upgrade --wait`.
 *
 * Refuses without a destination or a recovery passphrase: a backup whose
 * keys can't be decrypted would be a false promise.
 */
final readonly class StartControlPlaneBackup
{
    private const int STALE_GRACE_SECONDS = 600;

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(BackupReason $reason, ?User $user, AuditActor $actor, bool $dispatch = true): BackupRun
    {
        $run = DB::transaction(function () use ($reason, $user, $actor): BackupRun {
            $destination = BackupDestination::query()->whereNull('team_id')->lockForUpdate()->first();

            if (! $destination instanceof BackupDestination) {
                throw DomainRuleViolation::on('destination', 'Add a control-plane backup destination in Settings → Backups first.');
            }

            if (InstanceSettings::current()->recovery_public_key === null) {
                throw DomainRuleViolation::on('passphrase', 'Set a recovery passphrase first: without it the backed-up keys could never be decrypted.');
            }

            $inFlight = BackupRun::query()
                ->where('subject', BackupSubject::ControlPlane->value)
                ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
                ->first();

            // A run whose process died (worker killed, host lost) never
            // finishes by itself; past the task timeout it is given up.
            if ($inFlight instanceof BackupRun && ($inFlight->started_at ?? $inFlight->created_at)?->lt(now()->subSeconds(config()->integer('launchroom.backups.task_timeout_seconds') + self::STALE_GRACE_SECONDS))) {
                $inFlight->transitionTo(BackupRunStatus::Failed, 'Abandoned: the backup did not finish within its timeout.');
                $inFlight = null;
            }

            if ($inFlight instanceof BackupRun) {
                return $inFlight;
            }

            $run = BackupRun::query()->create([
                'team_id' => null,
                'schedule_id' => BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->value('id'),
                'destination_id' => $destination->id,
                'subject' => BackupSubject::ControlPlane,
                'reason' => $reason,
                'actor_id' => $user?->id,
            ]);

            if ($reason !== BackupReason::Scheduled) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::BackupStarted,
                    actor: $actor,
                    subjectType: 'control_plane',
                    subjectId: $run->public_id,
                    meta: ['reason' => $reason->value],
                ));
            }

            return $run;
        });

        if ($dispatch && $run->status === BackupRunStatus::Pending && $run->wasRecentlyCreated) {
            dispatch(new RunControlPlaneBackupJob($run->id));
        }

        return $run;
    }
}

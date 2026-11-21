<?php

declare(strict_types=1);

namespace App\Actions\AppUpdates;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Backups\StartServiceBackup;
use App\Data\AppUpdates\StartAppUpdateData;
use App\Data\AppUpdates\UpdateCandidateData;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AppUpdates\UpdateKind;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Exceptions\BackupRefused;
use App\Jobs\AppUpdates\AdvanceAppUpdateJob;
use App\Models\AppUpdates\AppUpdate;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\AppUpdates\UpdatePlan;
use App\Support\AppUpdates\UpdateChoice;
use App\Support\Images\ImageReference;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * Starts an update of an image service to one of the candidates of its
 * latest check (docs/04 §14.2), or says why it can't: the tag must be a
 * candidate (never trusted from the request), a major needs the release
 * notes read, and nothing else may be running or staged. Backs up the
 * service's data first when the team has backup storage; the rest happens
 * in AdvanceAppUpdate. Callers authorize (change the service and deploy).
 */
final readonly class StartAppUpdate
{
    public function __construct(
        private UpdatePlan $plan,
        private StartServiceBackup $backups,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, StartAppUpdateData $data, ?User $user, AuditActor $actor, bool $automatic = false): AppUpdate|Refusal
    {
        $refused = null;

        try {
            $update = $this->start($instance, $data, $user, $actor, $automatic, $refused);
        } catch (BackupRefused) {
            return Refusal::on('backup', 'launchroom couldn’t back up first, so it didn’t update. '.($refused->message ?? ''));
        }

        if ($update instanceof AppUpdate) {
            dispatch(new AdvanceAppUpdateJob($update->id));
        }

        return $update;
    }

    /**
     * @param-out Refusal|null $refused
     *
     * @throws BackupRefused
     */
    private function start(ServiceInstance $instance, StartAppUpdateData $data, ?User $user, AuditActor $actor, bool $automatic, ?Refusal &$refused): AppUpdate|Refusal
    {
        return DB::transaction(function () use ($instance, $data, $user, $actor, $automatic, &$refused): AppUpdate|Refusal {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment.lastAppliedRevision', 'volumes'])->lockForUpdate()->firstOrFail();
            $check = AppUpdateCheck::query()->where('service_instance_id', $instance->id)->first();
            $candidate = $check?->candidate($data->tag);

            if (! $check instanceof AppUpdateCheck || ! $candidate instanceof UpdateCandidateData || $candidate->digest === null || $check->image === null) {
                return Refusal::on('tag', "{$data->tag} isn’t an available update of {$instance->service->name}. Check again for updates.");
            }

            if ($candidate->kind === UpdateKind::Major && ! $data->releaseNotesRead) {
                return Refusal::on('releaseNotesRead', 'Read the release notes of a major update first: it can change settings and data.');
            }

            $blocker = $this->plan->blocker($instance);

            if ($blocker !== null) {
                return Refusal::conflict($blocker);
            }

            $from = ImageReference::tryParse($check->image);

            if (! $from instanceof ImageReference) {
                return Refusal::on('tag', 'The running image can’t be read. Check again for updates.');
            }

            $update = new AppUpdate([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'environment_id' => $instance->environment_id,
                'automatic' => $automatic,
                'kind' => $candidate->kind,
                'from_reference' => $check->image,
                'to_reference' => $from->registry.'/'.$from->repository.':'.$candidate->tag.'@'.$candidate->digest,
                'from_version' => UpdateChoice::runningVersion($check->image) ?? $check->image,
                'to_version' => $candidate->version,
                'actor_id' => $user?->id,
                'started_at' => now(),
            ]);
            $update->save();

            $runs = $this->backUp($instance, $update, $user, $actor);

            if ($runs instanceof Refusal) {
                // Nothing changes: the update row and any backup already started roll back.
                $refused = $runs;

                throw new BackupRefused;
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AppUpdateStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['update' => $update->public_id, 'environment' => $instance->environment->public_id, 'from' => $update->from_version, 'to' => $update->to_version, 'kind' => $update->kind->value, 'automatic' => $automatic],
            ));

            return $update;
        });
    }

    /**
     * Starts the backups of the service's data (reason `pre_update`) and
     * records them on the update, or why there are none.
     *
     * @return list<BackupRun>|Refusal
     */
    private function backUp(ServiceInstance $instance, AppUpdate $update, ?User $user, AuditActor $actor): array|Refusal
    {
        $subjects = $this->plan->backupSubjects($instance);

        if ($subjects === []) {
            $update->forceFill(['backup_note' => "{$instance->service->name} keeps no data of its own, so there is nothing to back up first."])->save();

            return [];
        }

        if (! $this->plan->hasBackupStorage($instance)) {
            $update->forceFill(['backup_note' => 'No backup storage yet, so there is no backup from before the update.'])->save();

            return [];
        }

        $runs = [];

        foreach ($subjects as $subject) {
            $started = $this->backups->handle($subject, BackupReason::PreUpdate, $user, $actor);

            if ($started instanceof Refusal) {
                return $started;
            }

            array_push($runs, ...$started);
        }

        $update->forceFill(['backup_run_ids' => array_map(static fn (BackupRun $run): int => $run->id, $runs)])->save();

        return $runs;
    }
}

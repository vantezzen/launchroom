<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupSubject;
use App\Events\Broadcasts\BackupsChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use Illuminate\Support\Facades\DB;

/**
 * "Back up this volume" (docs/14 §3): volumes are backed up by default; off
 * means no new backups of it from now on (scheduled, manual, safety and
 * final backups), and it no longer counts for alerts, verification,
 * replace-server or the final backup before it is deleted. Its existing
 * backups stay until the schedule's retention expires them. Who turned it
 * off and when is kept for the Backups page. Only volumes of services that
 * aren't databases (a database is backed up as a dump).
 */
final readonly class SetVolumeBackup
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return bool whether anything changed
     *
     * @throws DomainRuleViolation
     */
    public function handle(ServiceInstance $instance, Volume $volume, bool $enabled, User $user, AuditActor $actor): bool
    {
        if (BackupSubjects::for($instance) !== BackupSubject::Volume) {
            throw DomainRuleViolation::on('volume', 'A database is backed up as a whole. Turn off its automatic backups instead.');
        }

        $changed = DB::transaction(function () use ($instance, $volume, $enabled, $user, $actor): bool {
            $volume = Volume::query()->whereKey($volume->id)->where('service_instance_id', $instance->id)->lockForUpdate()->firstOrFail();

            if ($volume->backup_enabled === $enabled) {
                return false;
            }

            $volume->forceFill([
                'backup_enabled' => $enabled,
                'backup_disabled_by' => $enabled ? null : $user->id,
                'backup_disabled_at' => $enabled ? null : now(),
            ])->save();

            $instance->loadMissing(['service', 'environment']);
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupVolumeUpdated,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'backup' => $enabled ? 'on' : 'off', 'environment' => $instance->environment->public_id],
            ));

            return true;
        });

        if ($changed) {
            BackupsChanged::about($instance->team_id);
        }

        return $changed;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\WalArchive;
use App\Models\ServiceInstance;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\RestoreHolds;
use Illuminate\Support\Facades\DB;

/**
 * Turns point-in-time recovery of a Postgres database on or off and sets how
 * far back it reaches (docs/14 §10.1). Turning it on re-renders the
 * database's server: Postgres restarts once with archiving on, launchroomd
 * starts shipping WAL, and the next backup tick takes the first base backup.
 * Turning it off stops archiving (another restart); WAL and base backups
 * already stored age out with their retention.
 */
final readonly class UpdatePointInTimeRecovery
{
    public function __construct(
        private PointInTimeRecovery $pointInTime,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, bool $enabled, int $keepDays, AuditActor $actor): WalArchive
    {
        if ($keepDays < 1 || $keepDays > 35) {
            throw DomainRuleViolation::on('keepDays', 'Keep between 1 and 35 days.');
        }

        $instance->loadMissing(['service', 'environment']);

        if ($enabled && ! $this->pointInTime->supports($instance)) {
            throw DomainRuleViolation::on('service', 'Point-in-time recovery is available for Postgres 15 and newer outside previews.');
        }

        $archive = DB::transaction(function () use ($instance, $enabled, $keepDays): WalArchive {
            $archive = WalArchive::query()->where('service_instance_id', $instance->id)->lockForUpdate()->first();
            $destination = BackupSchedule::query()->where('service_instance_id', $instance->id)->first()?->destination()->first()
                ?? BackupDestination::defaultFor($instance->team_id);

            if (! $archive instanceof WalArchive) {
                if (! $destination instanceof BackupDestination) {
                    throw DomainRuleViolation::on('destination', 'Add a backup destination in Settings → Backups first.');
                }

                $hash = crc32($instance->public_id.'|base');
                $archive = new WalArchive([
                    'team_id' => $instance->team_id,
                    'service_instance_id' => $instance->id,
                    'destination_id' => $destination->id,
                    // Daily, staggered between 07:00 and 12:59 UTC (dumps run 01:00–06:59).
                    'base_cron' => ($hash % 60).' '.(intdiv($hash, 60) % 6 + 7).' * * *',
                ]);
            }

            $turningOn = $enabled && (! $archive->exists || ! $archive->enabled);
            $archive->forceFill([
                'enabled' => $enabled,
                'keep_days' => $keepDays,
                'enabled_at' => $turningOn ? now() : $archive->enabled_at,
            ]);

            if ($turningOn) {
                // A fresh start: the window begins with the first base backup.
                $archive->forceFill(['state' => null, 'checked_at' => null, 'last_archived_at' => null, 'last_segment' => null, 'pending_segments' => 0, 'message' => null, 'failing_since' => null, 'last_base_run_at' => null]);
            }

            $archive->save();

            return $archive;
        });

        $servers = RestoreHolds::serverIds([$instance->id]);

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::PointInTimeRecoveryUpdated,
            actor: $actor,
            teamId: $instance->team_id,
            subjectType: 'service',
            subjectId: $instance->service->public_id,
            meta: ['enabled' => $enabled, 'keepDays' => $keepDays, 'environment' => $instance->environment->public_id],
        ));

        return $archive;
    }
}

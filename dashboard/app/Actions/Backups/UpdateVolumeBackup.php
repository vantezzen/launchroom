<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\VolumeConsistency;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Support\Facades\DB;

/**
 * How a volume is read during backups (docs/14 §2): `live` (default) or
 * `pause` (the service's containers are frozen while it is read). Applies
 * from the next backup.
 */
final readonly class UpdateVolumeBackup
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, Volume $volume, VolumeConsistency $consistency, AuditActor $actor): void
    {
        DB::transaction(function () use ($instance, $volume, $consistency, $actor): void {
            $volume = Volume::query()->whereKey($volume->id)->where('service_instance_id', $instance->id)->lockForUpdate()->firstOrFail();

            if ($volume->backup_consistency === $consistency) {
                return;
            }

            $volume->backup_consistency = $consistency;
            $volume->save();

            $instance->loadMissing('service');
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::BackupVolumeUpdated,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'consistency' => $consistency->value],
            ));
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\RemoveVolumeData;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Volumes\StagedVolumes;
use Illuminate\Support\Facades\DB;

/**
 * Removes a volume from an app or image service (docs/14 §9.12).
 *
 * - Never created on a server: the row goes right away (nothing to lose).
 * - Holds files: the removal is staged (`removed_at`, typed confirmation).
 *   The running containers keep it until the next deploy replaces them;
 *   once no deployment mounts it, `VolumeRetirements::retireRemoved()`
 *   retires it like the volumes of a deleted environment (kept for the
 *   retention period; backed-up ones only deleted after an admin allows
 *   it, docs/05 §1.1). Discarding the change before the deploy keeps it.
 *
 * Refused while a backup, restore, import, export or server move works
 * with its files. Database volumes are managed by launchroom.
 */
final readonly class RemoveServiceVolume
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return bool true when the removal is staged for the next deploy, false when the volume is gone already
     */
    public function handle(ServiceInstance $instance, Volume $volume, RemoveVolumeData $data, AuditActor $actor): bool
    {
        return DB::transaction(function () use ($instance, $volume, $data, $actor): bool {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'volumes'])->lockForUpdate()->firstOrFail();
            $volume = $instance->volumes->firstWhere('id', $volume->id);

            if (! $volume instanceof Volume) {
                throw DomainRuleViolation::on('volume', 'This volume is already removed with the next deploy.');
            }

            if (! in_array($instance->service->kind, [ServiceKind::App, ServiceKind::Image], true)) {
                throw DomainRuleViolation::on('volume', 'A database keeps its data on this volume. Remove the database instead.');
            }

            $busy = StagedVolumes::busy($volume);

            if ($busy !== null) {
                throw DomainRuleViolation::on('volume', $busy);
            }

            $staged = $volume->server_id !== null;

            if ($staged && $data->confirm !== $volume->mount_path) {
                throw DomainRuleViolation::on('confirm', "Type {$volume->mount_path} to confirm.");
            }

            if ($staged) {
                $volume->forceFill(['removed_at' => now()])->save();
            } else {
                $volume->delete();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceVolumeRemoved,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'mountPath' => $volume->mount_path, 'instance' => $instance->public_id, 'staged' => $staged],
            ));

            return $staged;
        });
    }
}

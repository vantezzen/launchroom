<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeMount;
use Illuminate\Support\Facades\DB;

/**
 * Stops mounting another service's volume (D73). Staged: the running
 * containers keep it until the next deploy (they mount what their
 * deployment's revision recorded), and a discard brings it back. The
 * volume and its files stay with their owner.
 */
final readonly class DetachServiceVolume
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, Volume $volume, AuditActor $actor): void
    {
        DB::transaction(function () use ($instance, $volume, $actor): void {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with('service')->lockForUpdate()->firstOrFail();
            $mount = VolumeMount::query()->where('service_instance_id', $instance->id)->where('volume_id', $volume->id)->lockForUpdate()->first();

            if (! $mount instanceof VolumeMount) {
                throw DomainRuleViolation::on('volume', "{$instance->service->name} doesn’t use this volume.");
            }

            $mount->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceVolumeDetached,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'mountPath' => $mount->mount_path, 'instance' => $instance->public_id],
            ));
        });
    }
}

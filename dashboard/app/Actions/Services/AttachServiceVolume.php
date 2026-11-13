<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\AttachVolumeData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeMount;
use App\Services\Volumes\MountedVolumes;
use App\Services\Volumes\VolumeSharing;
use App\Support\Services\VolumeMountRules;
use Illuminate\Support\Facades\DB;

/**
 * Mounts a volume of another service into this one, at its own path (a
 * shared volume, D73). Staged like every other setting: the next deploy
 * mounts it. The volume keeps its owner (backups, SFTP and moves go
 * through it). A Docker volume lives on one server, so the service then
 * runs there too: refused when it can't (VolumeSharing), and a volume not
 * created yet is pinned to the server the service already keeps data on.
 */
final readonly class AttachServiceVolume
{
    public function __construct(
        private RecordAuditEvent $audit,
        private VolumeSharing $sharing,
    ) {}

    public function handle(ServiceInstance $instance, Volume $volume, AttachVolumeData $data, AuditActor $actor): VolumeMount
    {
        $path = $data->mountPath;
        $problem = VolumeMountRules::problem($path, []);

        if ($problem !== null) {
            throw DomainRuleViolation::on('mountPath', $problem);
        }

        return DB::transaction(function () use ($instance, $volume, $path, $actor): VolumeMount {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'environment'])->lockForUpdate()->firstOrFail();
            $volume = Volume::query()->whereKey($volume->id)->where('team_id', $instance->team_id)->lockForUpdate()->firstOrFail();
            $preview = $this->sharing->preview($instance, $volume, $path);

            if ($preview->problem !== null) {
                throw DomainRuleViolation::on('mountPath', $preview->problem);
            }

            if ($volume->server_id === null) {
                $pinned = MountedVolumes::pinnedServerId($instance);

                if ($pinned !== null) {
                    $volume->forceFill(['server_id' => $pinned])->save();
                }
            }

            $mount = VolumeMount::query()->create([
                'team_id' => $instance->team_id,
                'volume_id' => $volume->id,
                'service_instance_id' => $instance->id,
                'mount_path' => $path,
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceVolumeAttached,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'mountPath' => $path, 'instance' => $instance->public_id],
            ));

            return $mount;
        });
    }
}

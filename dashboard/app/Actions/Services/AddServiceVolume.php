<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\AddVolumeData;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use Illuminate\Support\Facades\DB;

/**
 * Adds a named volume to an app or image service in one environment
 * (uploads, SQLite files; docs/07 `volumes`). Staged like every other
 * setting: the next Deploy creates it on the server the service runs on,
 * and from then on the service is pinned there (docs/02 §6: services with
 * volumes are never moved automatically). Volumes are backed up as
 * snapshots once a backup destination exists (docs/14 §2).
 */
final readonly class AddServiceVolume
{
    /** Paths that may never be mount targets (launchroomd refuses them too, docs/09 R4). */
    private const array FORBIDDEN = ['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'];

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, AddVolumeData $data, AuditActor $actor): Volume
    {
        $path = $data->mountPath;

        if (array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..')
            || array_any(self::FORBIDDEN, static fn (string $forbidden): bool => $path === $forbidden || str_starts_with($path, $forbidden.'/'))) {
            throw DomainRuleViolation::on('mountPath', 'Pick another path: it must be clean and outside /proc, /sys and /dev.');
        }

        return DB::transaction(function () use ($instance, $path, $actor): Volume {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'volumes'])->lockForUpdate()->firstOrFail();

            if (! in_array($instance->service->kind, [ServiceKind::App, ServiceKind::Image], true)) {
                throw DomainRuleViolation::on('mountPath', 'Volumes can be added to app and image services. Databases already have theirs.');
            }

            if ($instance->volumes->contains(static fn (Volume $volume): bool => $volume->mount_path === $path || str_starts_with($path, rtrim($volume->mount_path, '/').'/') || str_starts_with($volume->mount_path, rtrim($path, '/').'/'))) {
                throw DomainRuleViolation::on('mountPath', 'This path is already (inside) a volume of this service.');
            }

            $volume = Volume::query()->create([
                'team_id' => $instance->team_id,
                'service_instance_id' => $instance->id,
                'mount_path' => $path,
            ]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceVolumeAdded,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'mountPath' => $path, 'instance' => $instance->public_id],
            ));

            return $volume;
        });
    }
}

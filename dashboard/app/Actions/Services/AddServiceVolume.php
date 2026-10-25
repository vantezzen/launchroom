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
use App\Services\Volumes\StagedVolumes;
use App\Support\Services\VolumeMountRules;
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
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, AddVolumeData $data, AuditActor $actor): Volume
    {
        $path = $data->mountPath;
        $problem = VolumeMountRules::problem($path, []);

        if ($problem !== null) {
            throw DomainRuleViolation::on('mountPath', $problem);
        }

        return DB::transaction(function () use ($instance, $path, $actor): Volume {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'volumes', 'environment.lastAppliedRevision'])->lockForUpdate()->firstOrFail();

            if (! in_array($instance->service->kind, [ServiceKind::App, ServiceKind::Image], true)) {
                throw DomainRuleViolation::on('mountPath', 'Volumes can be added to app and image services. Databases already have theirs.');
            }

            $problem = VolumeMountRules::problem($path, $instance->volumes->map(static fn (Volume $volume): string => $volume->mount_path)->all());

            if ($problem !== null) {
                throw DomainRuleViolation::on('mountPath', $problem);
            }

            // A volume at this path whose removal isn't deployed yet still
            // holds the files: keeping it is one discard away.
            if (StagedVolumes::pendingRemovals($instance)->contains('mount_path', $path)) {
                throw DomainRuleViolation::on('mountPath', "The volume at {$path} is removed with the next deploy. To keep it and its files, discard that change instead.");
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

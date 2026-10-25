<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\UpdateVolumeData;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Volumes\StagedVolumes;
use App\Support\Services\ConfigFileRules;
use App\Support\Services\VolumeMountRules;
use Illuminate\Support\Facades\DB;

/**
 * Moves a volume of an app or image service to another mount path. Staged:
 * the files stay in the volume, the running containers keep the old path
 * (they mount what their deployment recorded) and the next deploy mounts it
 * at the new one (docs/14 §9.12). Database volumes are managed by launchroom.
 */
final readonly class UpdateServiceVolume
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, Volume $volume, UpdateVolumeData $data, AuditActor $actor): Volume
    {
        $path = $data->mountPath;
        $problem = VolumeMountRules::problem($path, []);

        if ($problem !== null) {
            throw DomainRuleViolation::on('mountPath', $problem);
        }

        return DB::transaction(function () use ($instance, $volume, $path, $actor): Volume {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'volumes'])->lockForUpdate()->firstOrFail();
            $volume = $instance->volumes->firstWhere('id', $volume->id);

            if (! $volume instanceof Volume) {
                throw DomainRuleViolation::on('mountPath', 'This volume is removed with the next deploy. Discard that change to keep it.');
            }

            if (! in_array($instance->service->kind, [ServiceKind::App, ServiceKind::Image], true)) {
                throw DomainRuleViolation::on('mountPath', 'launchroom manages where a database keeps its data.');
            }

            $before = $volume->mount_path;

            if ($path === $before) {
                return $volume;
            }

            $problem = VolumeMountRules::problem($path, $instance->volumes->reject(static fn (Volume $other): bool => $other->id === $volume->id)->map(static fn (Volume $other): string => $other->mount_path)->all());

            if ($problem !== null) {
                throw DomainRuleViolation::on('mountPath', $problem);
            }

            // Config files never land inside a volume (D65).
            $mounts = $instance->volumes->map(static fn (Volume $other): string => $other->id === $volume->id ? $path : $other->mount_path)->all();
            $errors = ConfigFileRules::errors($instance->deploy->files, array_values($mounts));

            if ($errors !== []) {
                throw DomainRuleViolation::on('mountPath', $errors[0]);
            }

            $busy = StagedVolumes::busy($volume);

            if ($busy !== null) {
                throw DomainRuleViolation::on('mountPath', $busy);
            }

            $volume->forceFill(['mount_path' => $path])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceVolumeChanged,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: ['volume' => $volume->public_id, 'from' => $before, 'to' => $path, 'instance' => $instance->public_id],
            ));

            return $volume;
        });
    }
}

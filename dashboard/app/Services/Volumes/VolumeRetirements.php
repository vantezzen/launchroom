<?php

declare(strict_types=1);

namespace App\Services\Volumes;

use App\Data\Protocol\V1\VolumeDeletion;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\EnvironmentKind;
use App\Enums\VolumeRetirementStatus;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Models\VolumeRetirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Volume lifecycle after a deletion (docs/05 §1.1). Volumes are never
 * deleted because they left the desired state; deleting an environment,
 * project or preview records a retirement per volume instead:
 *
 * 1. retire(): before the rows go, each placed volume becomes a
 *    VolumeRetirement, retained for `launchroom.volumes.retention_hours`
 *    (`preview_retention_hours` for previews; 0 = next sweep). A volume
 *    whose service had backups (a successful run or an enabled schedule)
 *    is only deleted once its final backup exists (taken before the
 *    teardown, docs/14 §9.13: `final_backup_snapshot`) or the deletion was
 *    overridden ("delete without final backup", recorded as
 *    `confirmed_at/by`; the server page's "Allow deletion" is the same
 *    override for volumes retired before final backups existed).
 *    retireRemoved() does the same for volumes removed from a service
 *    (docs/14 §9.12) once the deploy applied the removal and no
 *    deployment mounts them any more.
 * 2. requestDue() (scheduled, App\Console\Commands\RetireVolumesCommand):
 *    due retirements become `requested`; their servers re-render.
 * 3. deletions(): requested ones are rendered into NodeState.deleteVolumes.
 * 4. recordOutcomes(): Report.volumeDeletions — deleted/absent finish the
 *    retirement; refused/failed keep it requested with the daemon's reason.
 */
final readonly class VolumeRetirements
{
    /**
     * @param  Collection<int, ServiceInstance>  $instances
     * @param  array<int, string>  $finalBackups  volume id => final backup snapshot id
     * @param  bool  $overridden  "delete without final backup" was chosen (by $overriddenBy, null: the system)
     * @param  list<int>|null  $only  just these volumes (null: every placed volume of the instances)
     */
    public function retire(Collection $instances, string $reason, array $finalBackups = [], bool $overridden = false, ?int $overriddenBy = null, ?array $only = null): void
    {
        if ($instances->isEmpty()) {
            return;
        }

        $instances->loadMissing(['service', 'environment.project']);
        $ids = $instances->modelKeys();
        $withBackups = [];

        foreach ([
            ...BackupRun::query()->whereIn('service_instance_id', $ids)->where('status', BackupRunStatus::Succeeded->value)->pluck('service_instance_id')->all(),
            ...BackupSchedule::query()->whereIn('service_instance_id', $ids)->where('enabled', true)->pluck('service_instance_id')->all(),
        ] as $id) {
            $withBackups[is_numeric($id) ? (int) $id : 0] = true;
        }

        $volumes = Volume::query()->whereIn('service_instance_id', $ids)->whereNotNull('server_id')->when($only !== null, static fn ($query) => $query->whereIn('id', $only ?? []))->orderBy('id')->get();
        $byId = $instances->keyBy('id');

        foreach ($volumes as $volume) {
            /** @var ServiceInstance $instance */
            $instance = $byId[$volume->service_instance_id];
            $environment = $instance->environment;
            $preview = $environment->kind === EnvironmentKind::Preview;
            $hours = config()->integer($preview ? 'launchroom.volumes.preview_retention_hours' : 'launchroom.volumes.retention_hours');
            $backups = isset($withBackups[$instance->id]);

            VolumeRetirement::query()->firstOrCreate(['volume_public_id' => $volume->public_id], [
                'team_id' => $volume->team_id,
                'server_id' => $volume->server_id,
                'docker_name' => $volume->dockerName(),
                'service_public_id' => $instance->service->public_id,
                'environment_public_id' => $environment->public_id,
                'label' => mb_substr("{$instance->service->name} in {$environment->project->name} / {$environment->name}", 0, 255),
                'reason' => $preview && $reason === 'environment_deleted' ? 'preview_destroyed' : $reason,
                'mount_path' => $volume->mount_path,
                'retain_until' => now()->addHours(max(0, $hours)),
                'backups_configured' => $backups,
                'final_backup_snapshot' => $backups ? ($finalBackups[$volume->id] ?? null) : null,
                'confirmed_at' => $backups && $overridden ? now() : null,
                'confirmed_by' => $backups && $overridden ? $overriddenBy : null,
            ]);
        }
    }

    /**
     * Volumes removed from a service (staged, docs/14 §9.12) whose removal
     * is deployed and that no deployment mounts any more: placed ones are
     * retired (reason `volume_removed`), then the rows go. One that is
     * still busy (a backup, restore, import, export or move) waits.
     *
     * @return int how many were retired or dropped
     */
    public function retireRemoved(): int
    {
        $done = 0;
        $volumes = Volume::query()
            ->whereNotNull('removed_at')
            ->with(['serviceInstance.service', 'serviceInstance.environment.lastAppliedRevision'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($volumes as $volume) {
            $instance = $volume->serviceInstance;

            if (isset(StagedVolumes::appliedPaths($instance)[$volume->public_id]) || StagedVolumes::mounted($volume) || StagedVolumes::busy($volume) !== null) {
                continue;
            }

            DB::transaction(function () use ($volume, $instance): void {
                $this->retire(new Collection([$instance]), 'volume_removed', only: [$volume->id]);
                $volume->delete();
            });
            $done++;
        }

        return $done;
    }

    /**
     * Moves due retirements to `requested`.
     *
     * @return list<int> servers whose NodeState must be re-rendered
     */
    public function requestDue(): array
    {
        $servers = [];

        $candidates = VolumeRetirement::query()
            ->where('status', VolumeRetirementStatus::Retained->value)
            ->where('retain_until', '<=', now())
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($candidates as $retirement) {
            if (! $retirement->isDue()) {
                continue;
            }

            $retirement->forceFill(['requested_at' => now()]);
            $retirement->transitionTo(VolumeRetirementStatus::Requested);
            $servers[] = $retirement->server_id;
        }

        return array_values(array_unique($servers));
    }

    /**
     * NodeState.deleteVolumes for a server, minus any volume it still declares.
     *
     * @param  list<string>  $declared  volume ids and names in the NodeState's `volumes`
     * @return list<VolumeDeletion>
     */
    public function deletions(Server $server, array $declared): array
    {
        $declared = array_flip($declared);

        return array_values(VolumeRetirement::query()
            ->where('server_id', $server->id)
            ->where('status', VolumeRetirementStatus::Requested->value)
            ->orderBy('volume_public_id')
            ->limit(1024)
            ->get()
            ->reject(static fn (VolumeRetirement $r): bool => isset($declared[$r->volume_public_id]) || isset($declared[$r->docker_name]))
            ->map(static fn (VolumeRetirement $r): VolumeDeletion => new VolumeDeletion(
                $r->volume_public_id,
                $r->docker_name,
                $r->service_public_id,
                $r->environment_public_id,
                CarbonImmutable::parse($r->requested_at ?? $r->updated_at ?? now()),
            ))
            ->all());
    }

    /**
     * Applies Report.volumeDeletions of a server.
     *
     * @param  list<array{id: string, name: string, outcome: string, message?: string|null}>  $results
     * @return bool whether a retirement finished (the NodeState drops it)
     */
    public function recordOutcomes(Server $server, array $results): bool
    {
        if ($results === []) {
            return false;
        }

        $byId = [];

        foreach ($results as $result) {
            $byId[$result['id']] = $result;
        }

        $finished = false;
        $retirements = VolumeRetirement::query()
            ->where('server_id', $server->id)
            ->where('status', VolumeRetirementStatus::Requested->value)
            ->whereIn('volume_public_id', array_keys($byId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($retirements as $retirement) {
            $result = $byId[$retirement->volume_public_id];

            if ($result['name'] !== $retirement->docker_name) {
                continue;
            }

            if (in_array($result['outcome'], ['deleted', 'absent'], true)) {
                $retirement->forceFill([
                    'deleted_at' => now(),
                    'message' => $result['outcome'] === 'absent' ? 'The volume no longer existed.' : null,
                ]);
                $retirement->transitionTo(VolumeRetirementStatus::Deleted);
                $finished = true;

                continue;
            }

            $message = isset($result['message']) ? mb_substr($result['message'], 0, 1024) : null;

            if ($retirement->message !== $message) {
                $retirement->forceFill(['message' => $message])->save();
            }
        }

        return $finished;
    }
}

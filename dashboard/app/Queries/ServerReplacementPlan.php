<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupRun;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;
use App\Services\Backups\BackupSubjects;
use Carbon\CarbonImmutable;

/**
 * What lived on a dead server (docs/14 §5.3): every service instance with a
 * placement or a volume there. Stateful ones (their volume is on it) are
 * restored on the replacement from their latest successful backup; the
 * others are re-placed. Used by the wizard (preview) and ReplaceServer.
 */
final class ServerReplacementPlan
{
    /**
     * @return list<array{instance: ServiceInstance, stateful: bool, restore: array{snapshot: string, takenAt: CarbonImmutable|null, subject: BackupSubject, volumes: array<int, string>}|null, reason: string|null}>
     */
    public function for(Server $server): array
    {
        /** @var list<int> $fromPlacements */
        $fromPlacements = Placement::query()->where('server_id', $server->id)->distinct()->pluck('service_instance_id')->all();
        /** @var list<int> $fromVolumes */
        $fromVolumes = Volume::query()->where('server_id', $server->id)->distinct()->pluck('service_instance_id')->all();

        // Removed services (staged removal deployed) are not brought back.
        $instances = ServiceInstance::query()
            ->whereKey(array_values(array_unique([...$fromPlacements, ...$fromVolumes])))
            ->where('team_id', $server->team_id)
            ->whereNull('removed_at')
            ->with(['service', 'environment.project', 'volumes'])
            ->orderBy('id')
            ->get();

        $plan = [];

        foreach ($instances as $instance) {
            $stateful = $instance->volumes->contains(static fn (Volume $volume): bool => $volume->server_id === $server->id);
            [$restore, $reason] = $stateful ? $this->latestBackup($instance) : [null, null];

            $plan[] = ['instance' => $instance, 'stateful' => $stateful, 'restore' => $restore, 'reason' => $reason];
        }

        return $plan;
    }

    /**
     * The latest successful backup of a stateful instance: a database dump,
     * or one snapshot per volume (all must exist).
     *
     * @return array{0: array{snapshot: string, takenAt: CarbonImmutable|null, subject: BackupSubject, volumes: array<int, string>}|null, 1: string|null}
     */
    private function latestBackup(ServiceInstance $instance): array
    {
        $subject = BackupSubjects::for($instance);

        if (! $subject instanceof BackupSubject) {
            return [null, 'This service holds data but has no kind of backup launchroom can restore.'];
        }

        $runs = BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('subject', $subject->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->latest('finished_at')
            ->orderByDesc('id')
            ->get();

        if ($subject === BackupSubject::Database) {
            $run = $runs->first();

            return $run instanceof BackupRun && $run->snapshot_id !== null
                ? [['snapshot' => $run->snapshot_id, 'takenAt' => $run->finished_at?->toImmutable(), 'subject' => $subject, 'volumes' => []], null]
                : [null, 'No backup exists: it starts empty.'];
        }

        $volumes = [];
        $oldest = null;

        foreach ($instance->volumes as $volume) {
            $run = $runs->first(static fn (BackupRun $run): bool => $run->volume_id === $volume->id);

            if (! $run instanceof BackupRun || $run->snapshot_id === null) {
                return [null, "No backup of the volume {$volume->mount_path} exists: it starts empty."];
            }

            $volumes[$volume->id] = $run->snapshot_id;
            $taken = $run->finished_at?->toImmutable();
            $oldest = $oldest === null || ($taken !== null && $taken->lt($oldest)) ? $taken : $oldest;
        }

        return [['snapshot' => (string) reset($volumes), 'takenAt' => $oldest, 'subject' => $subject, 'volumes' => $volumes], null];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Backups\BackupRunData;
use App\Data\Backups\BackupScheduleData;
use App\Data\Backups\BackupTrendPointData;
use App\Data\Backups\BackupVerificationData;
use App\Data\Backups\BackupVolumeData;
use App\Data\Backups\DeletionRequestData;
use App\Data\Backups\PointInTimeData;
use App\Data\Backups\RestoreRunData;
use App\Data\Backups\ServiceBackupsData;
use App\Data\Backups\SnapshotData;
use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\SnapshotListingStatus;
use App\Enums\Backups\SnapshotSource;
use App\Enums\DaemonFeature;
use App\Enums\DatabaseEngine;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\BackupVerification;
use App\Models\Backups\DeletionRequest;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the Backups tab payload for one database or volume-backed service
 * instance. Callers authorize (BackupRunPolicy::viewAny) first.
 */
final readonly class ServiceBackupsView
{
    /** Engines launchroomd can restore (Redis/Valkey: backup only). */
    public const array RESTORABLE = [DatabaseEngine::Postgres, DatabaseEngine::MySql, DatabaseEngine::MongoDb, DatabaseEngine::ClickHouse];

    public function __construct(
        private BackupStatuses $statuses,
        private SnapshotCache $cache,
        private PointInTimeRecovery $pointInTime,
    ) {}

    public function for(ServiceInstance $instance, User $user): ServiceBackupsData
    {
        $instance->loadMissing(['service', 'environment.project', 'volumes.server']);
        $source = DatabaseWorkloads::source($instance);
        $subject = BackupSubjects::for($instance);
        $status = $this->statuses->forInstance($instance);
        $mountPaths = $instance->volumes->mapWithKeys(static fn (Volume $volume): array => [$volume->public_id => $volume->mount_path])->all();
        $volumeIds = $instance->volumes->mapWithKeys(static fn (Volume $volume): array => [$volume->id => $volume->public_id])->all();

        $schedule = BackupSchedule::query()
            ->where('service_instance_id', $instance->id)
            ->with(['destination', 'serviceInstance.service', 'serviceInstance.environment.project'])
            ->first();
        $destination = $schedule->destination ?? BackupDestination::defaultFor($instance->team_id);

        $runs = BackupRun::query()->where('service_instance_id', $instance->id)->with('server')->orderByDesc('id')->limit(50)->get();
        $restores = RestoreRun::query()->where('source_service_instance_id', $instance->id)->with('target.service')->orderByDesc('id')->limit(20)->get();
        $listing = $this->cache->forInstance($instance);

        return new ServiceBackupsData(
            serviceInstanceId: $instance->public_id,
            serviceName: $instance->service->name,
            confirmationText: $instance->service->slug,
            subject: $subject,
            engine: $source?->engine->value,
            supported: $subject instanceof BackupSubject,
            restoreSupported: $subject === BackupSubject::Volume || ($source instanceof DatabaseSource && in_array($source->engine, self::RESTORABLE, true)),
            destinationName: $destination?->name,
            schedule: $schedule instanceof BackupSchedule ? BackupScheduleData::fromSchedule($schedule, $status, Gate::forUser($user)->allows('update', $schedule)) : null,
            status: $status,
            snapshots: $this->snapshots(array_values($runs->all()), $listing['snapshots'] ?? [], $volumeIds, $mountPaths),
            listingStatus: isset($listing['status']) ? SnapshotListingStatus::from($listing['status']) : null,
            listingFetchedAt: isset($listing['fetchedAt']) ? CarbonImmutable::parse($listing['fetchedAt']) : null,
            listingError: $listing['error'] ?? null,
            runs: array_values($runs->map(BackupRunData::fromRun(...))->all()),
            restores: array_values($restores->map(fn (RestoreRun $run): RestoreRunData => RestoreRunData::fromRun(
                $run,
                Gate::forUser($user)->allows('swap', $run) && RestoreSwaps::possible($run)
                    ? route('backups.services.restores.swap', ['serviceInstance' => $instance, 'restore' => $run->public_id])
                    : null,
            ))->all()),
            canBackup: Gate::forUser($user)->allows('create', [BackupRun::class, $instance]),
            canRestoreAsNew: Gate::forUser($user)->allows('create', [RestoreRun::class, $instance, RestoreMode::NewService]),
            canRestoreInPlace: Gate::forUser($user)->allows('create', [RestoreRun::class, $instance, RestoreMode::InPlace]),
            urls: [
                'self' => route('backups.services.show', $instance),
                'backup' => route('backups.services.backup', $instance),
                'refresh' => route('backups.services.snapshots', $instance),
                'restore' => route('backups.services.restore', $instance),
                'verify' => route('backups.services.verify', $instance),
            ],
            volumes: $subject === BackupSubject::Volume ? array_values($instance->volumes->sortBy('id')->map(static fn (Volume $volume): BackupVolumeData => new BackupVolumeData(
                $volume->public_id,
                $volume->mount_path,
                $volume->backup_consistency,
                $volume->server?->name,
                route('backups.services.volumes.update', ['serviceInstance' => $instance, 'volume' => $volume->public_id]),
            ))->all()) : [],
            canConfigure: Gate::forUser($user)->allows('configure', [BackupRun::class, $instance]),
            deletion: DeletionRequestData::openFor(DeletionRequest::SERVICE_INSTANCE, $instance->id, $user),
            pointInTime: $this->pointInTimeData($instance, $user),
            verification: ($verification = BackupVerification::query()->where('service_instance_id', $instance->id)->orderByDesc('id')->first()) instanceof BackupVerification
                ? BackupVerificationData::fromModel($verification) : null,
            canVerify: $subject instanceof BackupSubject && Gate::forUser($user)->allows('create', [BackupRun::class, $instance]),
            trend: $this->trend($instance),
        );
    }

    private function pointInTimeData(ServiceInstance $instance, User $user): ?PointInTimeData
    {
        $source = DatabaseWorkloads::source($instance);

        if (! $source instanceof DatabaseSource || $source->engine !== DatabaseEngine::Postgres) {
            return null;
        }

        $archive = WalArchive::query()->where('service_instance_id', $instance->id)->first();
        $enabled = $archive instanceof WalArchive && $archive->enabled;
        $window = $enabled ? $this->pointInTime->window($archive) : null;
        $next = $enabled ? $archive->nextBaseBackupAt(now()) : null;
        $server = DatabaseWorkloads::servingPlacement($instance)?->server;

        return new PointInTimeData(
            supported: $this->pointInTime->supports($instance),
            enabled: $enabled,
            keepDays: $archive->keep_days ?? 7,
            state: $enabled ? $archive->state : null,
            lastArchivedAt: $enabled ? $archive->last_archived_at : null,
            pendingSegments: $enabled ? $archive->pending_segments : 0,
            message: $enabled ? $archive->message : null,
            windowStart: $window[0] ?? null,
            windowEnd: $window[1] ?? null,
            nextBaseBackupAt: $next instanceof \DateTimeInterface ? CarbonImmutable::instance($next) : null,
            canConfigure: Gate::forUser($user)->allows('configure', [BackupRun::class, $instance]),
            serverReady: $server === null || $server->supportsDaemonFeature(DaemonFeature::PointInTime),
            updateUrl: route('backups.services.point-in-time', $instance),
        );
    }

    /**
     * @return list<BackupTrendPointData>
     */
    private function trend(ServiceInstance $instance): array
    {
        $dumps = BackupRun::query()->where('service_instance_id', $instance->id)->where('subject', '!=', BackupSubject::BaseBackup->value)->exists();
        $runs = BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('status', [BackupRunStatus::Succeeded->value, BackupRunStatus::Failed->value])
            ->whereNotNull('finished_at')
            // One kind per trend: base backups (point-in-time recovery) are
            // physical copies, orders of magnitude bigger than dumps.
            ->where('subject', $dumps ? '!=' : '=', BackupSubject::BaseBackup->value)
            ->latest('finished_at')
            ->limit(30)
            ->get();

        return array_values($runs->reverse()->map(static fn (BackupRun $run): BackupTrendPointData => new BackupTrendPointData(
            $run->public_id,
            $run->subject,
            $run->status,
            ($run->finished_at ?? now())->toImmutable(),
            $run->size_bytes,
            $run->duration_ms ?? ($run->started_at !== null && $run->finished_at !== null ? (int) $run->started_at->diffInMilliseconds($run->finished_at) : null),
        ))->all());
    }

    /**
     * Recorded runs plus repository-only snapshots (e.g. taken before this
     * dashboard existed), newest first, de-duplicated by id.
     *
     * @param  list<BackupRun>  $runs
     * @param  list<array{id: string, time: string, tags: list<string>, sizeBytes: int|null}>  $listed
     * @param  array<int, string>  $volumeIds  volume row id => public id
     * @param  array<string, string>  $mountPaths  volume public id => mount path
     * @return list<SnapshotData>
     */
    private function snapshots(array $runs, array $listed, array $volumeIds = [], array $mountPaths = []): array
    {
        $byId = [];

        foreach ($runs as $run) {
            // Base backups are restored through a point in time, not picked as snapshots.
            if ($run->status === BackupRunStatus::Succeeded && $run->snapshot_id !== null && $run->subject !== BackupSubject::BaseBackup) {
                $volume = $run->volume_id === null ? null : ($volumeIds[$run->volume_id] ?? null);
                $byId[$run->snapshot_id] = new SnapshotData(
                    $run->snapshot_id,
                    substr($run->snapshot_id, 0, 8),
                    ($run->finished_at ?? $run->created_at ?? now())->toImmutable(),
                    $run->size_bytes,
                    $run->reason->value,
                    SnapshotSource::Run,
                    $volume,
                    $volume === null ? null : ($mountPaths[$volume] ?? null),
                );
            }
        }

        foreach ($listed as $snapshot) {
            $volume = SnapshotTags::value($snapshot['tags'], 'volume');
            $byId[$snapshot['id']] ??= new SnapshotData(
                $snapshot['id'],
                substr($snapshot['id'], 0, 8),
                CarbonImmutable::parse($snapshot['time']),
                $snapshot['sizeBytes'],
                SnapshotTags::value($snapshot['tags'], 'reason'),
                SnapshotSource::Repository,
                $volume,
                $volume === null ? null : ($mountPaths[$volume] ?? null),
            );
        }

        $snapshots = array_values($byId);
        usort($snapshots, static fn (SnapshotData $a, SnapshotData $b): int => $b->time <=> $a->time);

        return $snapshots;
    }
}

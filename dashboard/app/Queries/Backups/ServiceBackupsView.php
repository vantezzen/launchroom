<?php

declare(strict_types=1);

namespace App\Queries\Backups;

use App\Data\Backups\BackupActionData;
use App\Data\Backups\BackupRunData;
use App\Data\Backups\BackupScheduleData;
use App\Data\Backups\BackupTrendPointData;
use App\Data\Backups\BackupVerificationData;
use App\Data\Backups\BackupVolumeData;
use App\Data\Backups\DeletionRequestData;
use App\Data\Backups\PointInTimeData;
use App\Data\Backups\RestoreDayData;
use App\Data\Backups\RestoreRunData;
use App\Data\Backups\ServiceBackupsData;
use App\Data\Backups\SnapshotData;
use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\Backups\BackupBlocker;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\SnapshotListingStatus;
use App\Enums\Backups\SnapshotSource;
use App\Enums\DaemonFeature;
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
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\RestoreSwaps;
use App\Services\Backups\SnapshotCache;
use App\Services\Backups\SnapshotTags;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the Backups tab payload for one database or volume-backed service
 * instance. Callers authorize (BackupRunPolicy::viewAny) first.
 */
final readonly class ServiceBackupsView
{
    public function __construct(
        private DatabaseEngines $engines,
        private BackupStatuses $statuses,
        private SnapshotCache $cache,
        private PointInTimeRecovery $pointInTime,
        private BackupProgress $progress,
    ) {}

    public function for(ServiceInstance $instance, User $user): ServiceBackupsData
    {
        $instance->loadMissing(['service', 'environment.project', 'volumes.server', 'volumes.backupDisabledBy']);
        $source = DatabaseWorkloads::source($instance);
        $subject = BackupSubjects::for($instance);
        $status = $this->statuses->forInstance($instance);
        $mountPaths = $instance->volumes->mapWithKeys(static fn (Volume $volume): array => [$volume->public_id => $volume->mount_path])->all();
        $volumeIds = $instance->volumes->mapWithKeys(static fn (Volume $volume): array => [$volume->id => $volume->public_id])->all();

        $schedule = BackupSchedule::query()
            ->where('service_instance_id', $instance->id)
            ->with(['destination', 'serviceInstance.service', 'serviceInstance.environment.project'])
            ->first();
        // Removed storage is history only: the current one is where the next backup goes.
        $destination = BackupDestination::forService($instance, $schedule instanceof BackupSchedule ? $schedule : null);

        $runs = BackupRun::query()->where('service_instance_id', $instance->id)->with('server')->orderByDesc('id')->limit(50)->get();
        $restores = RestoreRun::query()->where('source_service_instance_id', $instance->id)->with('target.service')->orderByDesc('id')->limit(20)->get();
        $listing = $this->cache->forInstance($instance);
        $inFlight = $runs->filter(static fn (BackupRun $run): bool => ! $run->status->isTerminal() && $run->subject !== BackupSubject::BaseBackup);
        $progress = array_values($this->progress->for($inFlight));
        $canBackup = Gate::forUser($user)->allows('create', [BackupRun::class, $instance]);
        $canConfigure = Gate::forUser($user)->allows('configure', [BackupRun::class, $instance]);
        $liveSizes = LiveVolumeSizes::of($instance->volumes);
        $snapshots = $this->snapshots(array_values($runs->all()), $listing['snapshots'] ?? [], $volumeIds, $mountPaths);

        return new ServiceBackupsData(
            serviceInstanceId: $instance->public_id,
            serviceName: $instance->service->name,
            confirmationText: $instance->service->slug,
            subject: $subject,
            engine: $source?->engine->value,
            supported: $subject instanceof BackupSubject,
            restoreSupported: $subject === BackupSubject::Volume || ($source instanceof DatabaseSource && $this->engines->for($source->engine)->restorable()),
            destinationName: $destination?->name,
            schedule: $schedule instanceof BackupSchedule ? BackupScheduleData::fromSchedule($schedule, $status, Gate::forUser($user)->allows('update', $schedule)) : null,
            status: $status,
            snapshots: $snapshots,
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
            canBackup: $canBackup,
            canRestoreAsNew: Gate::forUser($user)->allows('create', [RestoreRun::class, $instance, RestoreMode::NewService]),
            canRestoreInPlace: Gate::forUser($user)->allows('create', [RestoreRun::class, $instance, RestoreMode::InPlace]),
            urls: [
                'self' => route('backups.services.show', $instance),
                'backup' => route('backups.services.backup', $instance),
                'refresh' => route('backups.services.snapshots', $instance),
                'restore' => route('backups.services.restore', $instance),
                'verify' => route('backups.services.verify', $instance),
            ],
            volumes: $subject === BackupSubject::Volume ? $this->volumes($instance, $runs, $liveSizes, $canConfigure) : [],
            canConfigure: $canConfigure,
            deletion: DeletionRequestData::openFor(DeletionRequest::SERVICE_INSTANCE, $instance->id, $user),
            pointInTime: $this->pointInTimeData($instance, $user),
            verification: ($verification = BackupVerification::query()->where('service_instance_id', $instance->id)->orderByDesc('id')->first()) instanceof BackupVerification
                ? BackupVerificationData::fromModel($verification) : null,
            canVerify: $subject instanceof BackupSubject && Gate::forUser($user)->allows('create', [BackupRun::class, $instance]),
            trend: $this->trend($instance),
            inFlight: $progress,
            backUp: match (true) {
                ! $canBackup => BackupActionData::blocked(BackupBlocker::NoPermission, 'You need deploy access to back this up.'),
                $progress !== [] => BackupActionData::blocked(BackupBlocker::Running, 'A backup is running.'),
                ! $destination instanceof BackupDestination => BackupActionData::blocked(BackupBlocker::NoStorage, 'Connect backup storage to start.', 'Connect storage', route('backups.index').'#setup'),
                $instance->stopped_at !== null => BackupActionData::blocked(BackupBlocker::Stopped, "{$instance->service->name} is stopped. Start it to back it up."),
                default => BackupActionData::to(route('backups.services.backup', $instance)),
            },
            timeline: $this->timeline($snapshots),
            inPlaceTakesSafetyCopy: $destination instanceof BackupDestination,
        );
    }

    /**
     * @param  Collection<int, BackupRun>  $runs  newest first
     * @param  array<string, int>  $liveSizes
     * @return list<BackupVolumeData>
     */
    private function volumes(ServiceInstance $instance, Collection $runs, array $liveSizes, bool $canConfigure): array
    {
        return array_values($instance->volumes->sortBy('id')->map(static function (Volume $volume) use ($instance, $runs, $liveSizes, $canConfigure): BackupVolumeData {
            $last = $runs->first(static fn (BackupRun $run): bool => $run->volume_id === $volume->id && $run->status === BackupRunStatus::Succeeded);

            return new BackupVolumeData(
                $volume->public_id,
                $volume->mount_path,
                $volume->backup_consistency,
                $volume->server?->name,
                route('backups.services.volumes.update', ['serviceInstance' => $instance, 'volume' => $volume->public_id]),
                backupEnabled: $volume->backup_enabled,
                turnedOffBy: $volume->backup_enabled ? null : $volume->backupDisabledBy?->name,
                turnedOffAt: $volume->backup_enabled ? null : $volume->backup_disabled_at,
                backupUrl: $canConfigure ? route('backups.services.volumes.backup', ['serviceInstance' => $instance, 'volume' => $volume->public_id]) : null,
                liveSizeBytes: $liveSizes[$volume->public_id] ?? $volume->size_bytes,
                lastSizeBytes: $last?->size_bytes,
                lastSucceededAt: $last === null ? null : ($last->finished_at ?? $last->created_at),
                looksLikeCache: BackupSubjects::looksLikeCache($volume->mount_path),
            );
        })->all());
    }

    /**
     * Restore points by day (UTC), newest first (the restore panel's timeline).
     *
     * @param  list<SnapshotData>  $snapshots  newest first
     * @return list<RestoreDayData>
     */
    private function timeline(array $snapshots): array
    {
        $days = [];

        foreach ($snapshots as $snapshot) {
            $days[$snapshot->time->copy()->utc()->format('Y-m-d')][] = $snapshot;
        }

        $out = [];

        foreach ($days as $date => $points) {
            $out[] = new RestoreDayData((string) $date, $points);
        }

        return $out;
    }

    private function pointInTimeData(ServiceInstance $instance, User $user): ?PointInTimeData
    {
        $source = DatabaseWorkloads::source($instance);

        if (! $source instanceof DatabaseSource || ! $this->pointInTime->offered($source->engine)) {
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
            ->whereIn('status', BackupRunStatus::terminal())
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

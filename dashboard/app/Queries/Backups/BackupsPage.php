<?php

declare(strict_types=1);

namespace App\Queries\Backups;

use App\Data\Backups\BackupActionData;
use App\Data\Backups\BackupAttentionData;
use App\Data\Backups\BackupBatchProgressData;
use App\Data\Backups\BackupDayData;
use App\Data\Backups\BackupDestinationData;
use App\Data\Backups\BackupEverythingData;
use App\Data\Backups\BackupGroupData;
use App\Data\Backups\BackupItemData;
use App\Data\Backups\BackupItemScheduleData;
use App\Data\Backups\BackupLiveData;
use App\Data\Backups\BackupRunData;
use App\Data\Backups\BackupRunProgressData;
use App\Data\Backups\BackupScheduleData;
use App\Data\Backups\BackupsPageData;
use App\Data\Backups\BackupsSummaryData;
use App\Data\Backups\BackupStorageData;
use App\Data\Backups\BackupVolumeSwitchData;
use App\Data\Backups\LaunchroomBackupData;
use App\Data\Backups\NotBackedUpItemData;
use App\Data\Backups\RecoveryKitData;
use App\Data\Backups\RemovedDestinationData;
use App\Data\Backups\ServiceBackupStatusData;
use App\Data\Services\DatabaseSource;
use App\Enums\Backups\BackupBlocker;
use App\Enums\Backups\BackupItemKind;
use App\Enums\Backups\BackupItemState;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupsSummaryState;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\DestinationStatus;
use App\Enums\Backups\NotBackedUpReason;
use App\Enums\Backups\RecoveryKitStep;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Volume;
use App\Queries\BackupOverview;
use App\Services\Backups\BackupBatches;
use App\Services\Backups\BackupSubjects;
use App\Support\Backups\CronDescription;
use App\Support\Links;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Props of the Backups page (docs/14 §3, docs/design/flows.md §14): one row
 * per database, per volume and for launchroom itself, grouped by project,
 * with live state (queued, running with progress, failed, overdue), the
 * "Not backed up" items with their reasons, the storage, launchroom's own
 * setup and the recovery kit. Every action that can't run carries its
 * reason and fix. The number of queries does not grow with the number of
 * items (tests/Feature/Backups/BackupsPageTest.php budgets it).
 */
final readonly class BackupsPage
{
    private const int POLL_MS = 5000;

    private const int BATCH_VISIBLE_SECONDS = 3600;

    public function __construct(
        private BackupOverview $overview,
        private BackupProgress $progress,
        private BackupStatuses $statuses,
    ) {}

    public function for(Team $team, User $user): BackupsPageData
    {
        $gate = Gate::forUser($user);
        $now = CarbonImmutable::now();
        $canDeploy = $gate->allows('createAny', [BackupRun::class, $team]);
        $canManage = $gate->allows('create', [BackupDestination::class, $team]);
        $isOwner = $gate->allows('manageInstance', BackupDestination::class);
        $showLaunchroom = $isOwner || $canManage;

        $destinations = BackupDestination::query()->where('team_id', $team->id)->withCount('schedules')->orderByDesc('is_default')->orderBy('id')->get();
        $instanceDestination = $showLaunchroom ? BackupDestination::query()->instanceWide()->withCount('schedules')->first() : null;
        $hasStorage = $destinations->isNotEmpty();

        $instances = ServiceInstance::query()
            ->where('team_id', $team->id)
            ->whereNull('removed_at')
            ->where(static fn (Builder $query): Builder => $query
                ->whereHas('service', static fn (Builder $service): Builder => $service->where('kind', ServiceKind::Database->value))
                ->orWhereHas('volumes'))
            ->with(['service', 'environment.project', 'volumes.server', 'volumes.backupDisabledBy'])
            ->get();
        $schedules = BackupSchedule::query()
            ->whereIn('service_instance_id', $instances->modelKeys())
            ->with('destination')
            ->get()
            ->keyBy('service_instance_id');
        $controlSchedule = $showLaunchroom ? BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->with('destination')->first() : null;

        $runs = $this->runs($team, $instances, $showLaunchroom);
        $progress = $this->progress->for($runs['inFlight']);
        $liveSizes = LiveVolumeSizes::of($instances->flatMap(static fn (ServiceInstance $instance) => $instance->volumes));
        $health = $this->statuses->forInstances(array_values($instances->all()), $team->id);

        $items = [];
        $notBackedUp = [];
        $previews = [];
        /** @var array<string, string> $projects project name => public id */
        $projects = $instances->mapWithKeys(static fn (ServiceInstance $instance): array => [$instance->environment->project->name => $instance->environment->project->public_id])->all();

        foreach ($instances as $instance) {
            $subject = BackupSubjects::for($instance);

            if (! $subject instanceof BackupSubject) {
                continue;
            }

            $environment = $instance->environment;

            if ($environment->kind === EnvironmentKind::Preview) {
                $previews[$environment->id] ??= new NotBackedUpItemData(
                    'environment:'.$environment->public_id,
                    'environment',
                    $environment->name,
                    null,
                    $environment->project->name,
                    $environment->name,
                    NotBackedUpReason::Preview,
                    null,
                    null,
                    null,
                );

                continue;
            }

            $schedule = $schedules->get($instance->id);
            $schedule = $schedule instanceof BackupSchedule ? $schedule : null;

            if ($schedule instanceof BackupSchedule && ! $schedule->enabled) {
                $notBackedUp[] = new NotBackedUpItemData(
                    ($subject === BackupSubject::Database ? 'database:' : 'service:').$instance->public_id,
                    $subject === BackupSubject::Database ? 'database' : 'service',
                    $instance->service->name,
                    $instance->service->name,
                    $environment->project->name,
                    $environment->name,
                    NotBackedUpReason::ScheduleOff,
                    null,
                    $schedule->updated_at,
                    $gate->allows('update', $schedule) ? BackupActionData::to(route('backups.schedules.update', $schedule->public_id)) : null,
                    $this->scheduleDetail($schedule, $instance, $health, $gate),
                    Links::serviceSettings($instance, 'backups'),
                );

                continue;
            }

            if ($subject === BackupSubject::Database) {
                $items[] = $this->databaseItem($instance, $schedule, $runs, $progress, $health, $gate, $canDeploy, $hasStorage, $now);

                continue;
            }

            foreach ($instance->volumes->sortBy('id') as $volume) {
                if (BackupSubjects::excluded($volume)) {
                    $notBackedUp[] = new NotBackedUpItemData(
                        'volume:'.$volume->public_id,
                        'volume',
                        $volume->mount_path,
                        $instance->service->name,
                        $environment->project->name,
                        $environment->name,
                        NotBackedUpReason::VolumeOff,
                        $volume->backupDisabledBy?->name,
                        $volume->backup_disabled_at,
                        $gate->allows('configure', [BackupRun::class, $instance]) ? BackupActionData::to($this->volumeSwitchUrl($instance, $volume)) : null,
                        null,
                        Links::serviceSettings($instance, 'backups'),
                    );

                    continue;
                }

                $items[] = $this->volumeItem($instance, $volume, $schedule, $runs, $progress, $liveSizes, $health, $gate, $canDeploy, $hasStorage, $now);
            }
        }

        $launchroom = $showLaunchroom ? $this->launchroom($isOwner, $destinations, $instanceDestination, $controlSchedule, $runs, $progress, $now) : null;
        $groups = $this->groups($items, $launchroom?->item, $projects);
        $all = [];

        foreach ($groups as $group) {
            array_push($all, ...$group->items);
        }

        $batch = $this->batch($team, $all);
        $testing = $destinations->contains(static fn (BackupDestination $d): bool => in_array($d->status, [DestinationStatus::Checking, DestinationStatus::Unverified], true))
            || ($instanceDestination instanceof BackupDestination && in_array($instanceDestination->status, [DestinationStatus::Checking, DestinationStatus::Unverified], true));
        $active = $progress !== [] || $testing || ($batch instanceof BackupBatchProgressData && ! $batch->finishedAt instanceof CarbonInterface);

        return new BackupsPageData(
            teamId: $team->public_id,
            summary: $this->summary($all, $hasStorage || $instanceDestination instanceof BackupDestination),
            groups: $groups,
            notBackedUp: [...$notBackedUp, ...array_values($previews)],
            storage: new BackupStorageData(
                destinations: $this->destinations($destinations, $instanceDestination, $gate, $runs['destinationRuns']),
                removed: $this->removed($team, $destinations, $gate),
                canManage: $canManage,
                add: $canManage ? BackupActionData::to(route('backups.destinations.store')) : BackupActionData::blocked(BackupBlocker::NoPermission, 'Only team owners and admins can connect backup storage.'),
                canProtectLaunchroom: $isOwner && ! $instanceDestination instanceof BackupDestination,
            ),
            launchroom: $launchroom,
            everything: new BackupEverythingData($this->everythingAction($all, $canDeploy, $hasStorage, $batch), $batch),
            live: new BackupLiveData('backups.'.$team->public_id, '.backups.changed', $active ? self::POLL_MS : null),
        );
    }

    /**
     * The runs the rows need, in a fixed number of queries: the last 30
     * days (strips), the newest success and the newest finished run per
     * item, and everything in flight.
     *
     * @param  Collection<int, ServiceInstance>  $instances
     * @return array{recent: Collection<int, BackupRun>, success: array<string, BackupRun>, finished: array<string, BackupRun>, inFlight: Collection<int, BackupRun>, destinationRuns: array<int, int>}
     */
    private function runs(Team $team, Collection $instances, bool $controlPlane): array
    {
        $ids = $instances->modelKeys();
        $scope = static function (Builder $query) use ($ids, $controlPlane): void {
            $query->where(static function (Builder $runs) use ($ids, $controlPlane): void {
                $runs->where(static fn (Builder $q) => $q->whereIn('service_instance_id', $ids)->whereIn('subject', [BackupSubject::Database->value, BackupSubject::Volume->value]));

                if ($controlPlane) {
                    $runs->orWhere('subject', BackupSubject::ControlPlane->value);
                }
            });
        };
        $since = CarbonImmutable::now('UTC')->startOfDay()->subDays(BackupOverview::DAYS - 1);
        $columns = ['id', 'public_id', 'team_id', 'subject', 'service_instance_id', 'volume_id', 'status', 'reason', 'size_bytes', 'error', 'created_at', 'started_at', 'finished_at', 'daemon_task_id', 'server_id', 'duration_ms'];

        $recent = BackupRun::query()->where($scope)->whereIn('status', BackupRunStatus::terminal())->where('created_at', '>=', $since)->orderBy('id')->get(['id', 'subject', 'service_instance_id', 'volume_id', 'status', 'created_at']);
        $newest = static fn (array $statuses) => BackupRun::query()
            ->whereIn('id', BackupRun::query()->where($scope)->whereIn('status', $statuses)->selectRaw('max(id)')->groupBy('subject', 'service_instance_id', 'volume_id'))
            ->get($columns);
        $inFlight = BackupRun::query()->where($scope)->whereIn('status', BackupRunStatus::nonTerminal())->with('server')->orderBy('id')->get($columns);

        $key = fn (BackupRun $run): string => $this->runKey($run);

        /** @var array<int, int> $destinationRuns */
        $destinationRuns = BackupRun::query()->where('team_id', $team->id)->orWhereNull('team_id')->groupBy('destination_id')->selectRaw('destination_id, count(*) as runs')->pluck('runs', 'destination_id')->map(static fn (mixed $runs): int => is_numeric($runs) ? (int) $runs : 0)->all();

        return [
            'recent' => $recent,
            'success' => $newest([BackupRunStatus::Succeeded->value])->keyBy($key)->all(),
            'finished' => $newest(BackupRunStatus::terminal())->keyBy($key)->all(),
            'inFlight' => $inFlight,
            'destinationRuns' => $destinationRuns,
        ];
    }

    private function runKey(BackupRun $run): string
    {
        return match ($run->subject) {
            BackupSubject::ControlPlane => 'launchroom',
            BackupSubject::Volume => 'volume:'.$run->volume_id,
            default => 'database:'.$run->service_instance_id,
        };
    }

    /**
     * @param  array{recent: Collection<int, BackupRun>, success: array<string, BackupRun>, finished: array<string, BackupRun>, inFlight: Collection<int, BackupRun>, destinationRuns: array<int, int>}  $runs
     * @param  array<int, BackupRunProgressData>  $progress
     * @param  array<int, ServiceBackupStatusData>  $health
     */
    private function databaseItem(ServiceInstance $instance, ?BackupSchedule $schedule, array $runs, array $progress, array $health, GateContract $gate, bool $canDeploy, bool $hasStorage, CarbonImmutable $now): BackupItemData
    {
        $key = 'database:'.$instance->id;
        $source = $instance->service->source;
        $inFlight = $runs['inFlight']->first(static fn (BackupRun $run): bool => $run->subject === BackupSubject::Database && $run->service_instance_id === $instance->id);
        $run = $inFlight instanceof BackupRun ? ($progress[$inFlight->id] ?? null) : null;
        $removed = $schedule?->destination->trashed() ?? false;

        $backUp = $this->serviceAction($instance, $run, $canDeploy, $hasStorage, $removed, $instance->active_deployment_id === null, null)
            ?? BackupActionData::to(route('backups.services.run', $instance));

        return $this->item(
            id: 'database:'.$instance->public_id,
            kind: BackupItemKind::Database,
            name: $instance->service->name,
            instance: $instance,
            volume: null,
            engine: $source instanceof DatabaseSource ? $source->engine->value : null,
            engineVersion: $source instanceof DatabaseSource ? $source->version : null,
            schedule: $schedule,
            runs: $runs,
            key: $key,
            run: $run,
            backUp: $backUp,
            volumeBackup: null,
            liveSize: null,
            health: $health,
            gate: $gate,
            removed: $removed,
            now: $now,
        );
    }

    /**
     * @param  array{recent: Collection<int, BackupRun>, success: array<string, BackupRun>, finished: array<string, BackupRun>, inFlight: Collection<int, BackupRun>, destinationRuns: array<int, int>}  $runs
     * @param  array<int, BackupRunProgressData>  $progress
     * @param  array<string, int>  $liveSizes
     * @param  array<int, ServiceBackupStatusData>  $health
     */
    private function volumeItem(ServiceInstance $instance, Volume $volume, ?BackupSchedule $schedule, array $runs, array $progress, array $liveSizes, array $health, GateContract $gate, bool $canDeploy, bool $hasStorage, CarbonImmutable $now): BackupItemData
    {
        $inFlight = $runs['inFlight']->first(static fn (BackupRun $run): bool => $run->volume_id === $volume->id);
        $run = $inFlight instanceof BackupRun ? ($progress[$inFlight->id] ?? null) : null;
        $removed = $schedule?->destination->trashed() ?? false;
        $offline = $volume->server !== null && $volume->server->status !== ServerStatus::Online ? $volume->server : null;

        $backUp = $this->serviceAction($instance, $run, $canDeploy, $hasStorage, $removed, $volume->server_id === null, $offline?->name)
            ?? BackupActionData::to(route('backups.services.volumes.run', ['serviceInstance' => $instance, 'volume' => $volume->public_id]));

        return $this->item(
            id: 'volume:'.$volume->public_id,
            kind: BackupItemKind::Volume,
            name: $volume->mount_path,
            instance: $instance,
            volume: $volume,
            engine: null,
            engineVersion: null,
            schedule: $schedule,
            runs: $runs,
            key: 'volume:'.$volume->id,
            run: $run,
            backUp: $backUp,
            volumeBackup: new BackupVolumeSwitchData(
                true,
                $gate->allows('configure', [BackupRun::class, $instance]) ? $this->volumeSwitchUrl($instance, $volume) : null,
                BackupSubjects::looksLikeCache($volume->mount_path),
            ),
            liveSize: $liveSizes[$volume->public_id] ?? $volume->size_bytes,
            health: $health,
            gate: $gate,
            removed: $removed,
            now: $now,
        );
    }

    /**
     * The blocked "Back up now" of a database or volume row, or null when it can run.
     */
    private function serviceAction(ServiceInstance $instance, ?BackupRunProgressData $run, bool $canDeploy, bool $hasStorage, bool $removed, bool $notDeployed, ?string $offlineServer): ?BackupActionData
    {
        $service = $instance->service->name;
        $link = Links::service($instance);

        return match (true) {
            ! $canDeploy => BackupActionData::blocked(BackupBlocker::NoPermission, 'You need deploy access to back this up.'),
            $run instanceof BackupRunProgressData => BackupActionData::blocked(BackupBlocker::Running, 'A backup is running.'),
            ! $hasStorage => BackupActionData::blocked(BackupBlocker::NoStorage, 'Connect backup storage to start.', 'Connect storage', '#setup'),
            $removed => BackupActionData::blocked(BackupBlocker::StorageRemoved, 'Its backup storage was removed.', 'Connect storage', '#setup'),
            $instance->stopped_at !== null => BackupActionData::blocked(BackupBlocker::Stopped, "{$service} is stopped. Start it to back it up.", "Open {$service}", $link),
            $notDeployed => BackupActionData::blocked(BackupBlocker::NotDeployed, "{$service} hasn’t been deployed yet.", "Open {$service}", $link),
            $offlineServer !== null => BackupActionData::blocked(BackupBlocker::ServerOffline, "{$offlineServer} isn’t reporting, so the volume can’t be read right now.", "Open {$service}", $link),
            default => null,
        };
    }

    /**
     * @param  array{recent: Collection<int, BackupRun>, success: array<string, BackupRun>, finished: array<string, BackupRun>, inFlight: Collection<int, BackupRun>, destinationRuns: array<int, int>}  $runs
     * @param  array<int, ServiceBackupStatusData>  $health
     */
    private function item(
        string $id,
        BackupItemKind $kind,
        string $name,
        ServiceInstance $instance,
        ?Volume $volume,
        ?string $engine,
        ?string $engineVersion,
        ?BackupSchedule $schedule,
        array $runs,
        string $key,
        ?BackupRunProgressData $run,
        BackupActionData $backUp,
        ?BackupVolumeSwitchData $volumeBackup,
        ?int $liveSize,
        array $health,
        GateContract $gate,
        bool $removed,
        CarbonImmutable $now,
    ): BackupItemData {
        $success = $runs['success'][$key] ?? null;
        $finished = $runs['finished'][$key] ?? null;
        $lastAt = $success === null ? null : ($success->finished_at ?? $success->created_at)?->toImmutable();
        $next = $schedule instanceof BackupSchedule && ! $removed && $instance->stopped_at === null ? $schedule->nextRunAt($now) : null;
        $state = $this->state($run, $finished, $lastAt, $instance->stopped_at !== null, $schedule instanceof BackupSchedule && ! $removed, $now);
        $environment = $instance->environment;
        $href = Links::serviceSettings($instance, 'backups');
        $shared = $volume instanceof Volume ? $instance->volumes->filter(static fn (Volume $v): bool => ! BackupSubjects::excluded($v))->count() : 1;

        return new BackupItemData(
            id: $id,
            kind: $kind,
            name: $name,
            serviceName: $instance->service->name,
            mountPath: $volume?->mount_path,
            engine: $engine,
            engineVersion: $engineVersion,
            projectName: $environment->project->name,
            environmentName: $environment->name,
            environmentKind: $environment->kind,
            serviceInstanceId: $instance->public_id,
            volumeId: $volume?->public_id,
            state: $state,
            lastSucceededAt: $lastAt,
            lastSizeBytes: $success?->size_bytes,
            liveSizeBytes: $liveSize,
            nextRunAt: $next instanceof \DateTimeInterface ? CarbonImmutable::instance($next) : null,
            lastError: $state === BackupItemState::Failed ? $this->firstLine($finished?->error) : null,
            days: $this->days($runs['recent'], $key),
            schedule: $schedule instanceof BackupSchedule ? new BackupItemScheduleData(
                $schedule->public_id,
                $schedule->cron,
                CronDescription::describe($schedule->cron),
                $schedule->retention->summary(),
                $schedule->enabled,
                $shared,
                $gate->allows('update', $schedule) ? route('backups.schedules.update', $schedule->public_id) : null,
                $this->scheduleDetail($schedule, $instance, $health, $gate),
            ) : null,
            run: $run,
            backUp: $backUp,
            volumeBackup: $volumeBackup,
            restoreHref: $href,
            historyHref: $href,
            storageRemoved: $removed,
        );
    }

    /**
     * @param  array<int, ServiceBackupStatusData>  $health
     */
    private function scheduleDetail(BackupSchedule $schedule, ServiceInstance $instance, array $health, GateContract $gate): ?BackupScheduleData
    {
        $status = $health[$instance->id] ?? null;

        if (! $status instanceof ServiceBackupStatusData) {
            return null;
        }

        $schedule->setRelation('serviceInstance', $instance);

        return BackupScheduleData::fromSchedule($schedule, $status, $gate->allows('update', $schedule));
    }

    private function state(?BackupRunProgressData $run, ?BackupRun $finished, ?CarbonImmutable $lastSuccess, bool $stopped, bool $scheduled, CarbonImmutable $now): BackupItemState
    {
        $staleAfter = config()->integer('launchroom.backups.stale_after_hours');

        return match (true) {
            $run instanceof BackupRunProgressData => $run->state === 'running' ? BackupItemState::Running : BackupItemState::Queued,
            $finished?->status === BackupRunStatus::Failed => BackupItemState::Failed,
            $stopped => BackupItemState::Paused,
            $lastSuccess instanceof CarbonImmutable && $scheduled && $lastSuccess->lt($now->subHours($staleAfter)) => BackupItemState::Overdue,
            $lastSuccess instanceof CarbonImmutable => BackupItemState::Ok,
            default => BackupItemState::Waiting,
        };
    }

    /**
     * @param  Collection<int, BackupRun>  $recent
     * @return list<BackupDayData>
     */
    private function days(Collection $recent, string $key): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $since = $today->subDays(BackupOverview::DAYS - 1);
        $byDay = [];

        foreach ($recent as $run) {
            if ($this->runKey($run) !== $key) {
                continue;
            }

            $day = ($run->created_at ?? $today)->copy()->utc()->format('Y-m-d');
            $ok = $run->status === BackupRunStatus::Succeeded;
            $byDay[$day] = $ok || ($byDay[$day] ?? null) === 'ok' ? 'ok' : 'failed';
        }

        $days = [];

        for ($i = 0; $i < BackupOverview::DAYS; $i++) {
            $date = $since->addDays($i)->format('Y-m-d');
            $days[] = new BackupDayData($date, $byDay[$date] ?? 'none');
        }

        return $days;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function copiedFrom(BackupDestination $destination): ?string
    {
        $id = $destination->copied_from_id === null ? null : BackupDestination::withTrashed()->whereKey($destination->copied_from_id)->value('public_id');

        return is_string($id) ? $id : null;
    }

    private static function count(BackupDestination $destination): int
    {
        $count = $destination->getAttribute('schedules_count');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function firstLine(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $line = trim(strtok($error, "\n") ?: $error);

        return mb_substr($line, 0, 300);
    }

    private function volumeSwitchUrl(ServiceInstance $instance, Volume $volume): string
    {
        return route('backups.services.volumes.backup', ['serviceInstance' => $instance, 'volume' => $volume->public_id]);
    }

    /**
     * @param  Collection<int, BackupDestination>  $destinations
     * @param  array{recent: Collection<int, BackupRun>, success: array<string, BackupRun>, finished: array<string, BackupRun>, inFlight: Collection<int, BackupRun>, destinationRuns: array<int, int>}  $runs
     * @param  array<int, BackupRunProgressData>  $progress
     */
    private function launchroom(bool $isOwner, Collection $destinations, ?BackupDestination $destination, ?BackupSchedule $schedule, array $runs, array $progress, CarbonImmutable $now): LaunchroomBackupData
    {
        $settings = InstanceSettings::current();
        $passphrase = $settings->recovery_public_key !== null;
        $configured = $destination instanceof BackupDestination;
        $inFlight = $runs['inFlight']->first(static fn (BackupRun $run): bool => $run->subject === BackupSubject::ControlPlane);
        $run = $inFlight instanceof BackupRun ? ($progress[$inFlight->id] ?? null) : null;
        $success = $runs['success']['launchroom'] ?? null;
        $finished = $runs['finished']['launchroom'] ?? null;
        $lastAt = $success === null ? null : ($success->finished_at ?? $success->created_at)?->toImmutable();
        $ready = $configured && $passphrase;
        $next = $ready && $schedule instanceof BackupSchedule ? $schedule->nextRunAt($now) : null;
        $state = $this->state($run, $finished, $lastAt, false, $ready && $schedule instanceof BackupSchedule, $now);

        $backUp = match (true) {
            ! $isOwner => BackupActionData::blocked(BackupBlocker::OwnerOnly, 'Your launchroom’s owner sets this up.'),
            $run instanceof BackupRunProgressData => BackupActionData::blocked(BackupBlocker::Running, 'A backup is running.'),
            ! $configured => BackupActionData::blocked(BackupBlocker::NoLaunchroomStorage, 'launchroom itself isn’t backed up yet.', 'Set up', '#setup-launchroom'),
            ! $passphrase => BackupActionData::blocked(BackupBlocker::NoPassphrase, 'Needs a recovery passphrase.', 'Set passphrase', '#recovery-kit'),
            default => BackupActionData::to(route('backups.control-plane.run')),
        };

        $item = new BackupItemData(
            id: 'launchroom',
            kind: BackupItemKind::ControlPlane,
            name: 'launchroom',
            serviceName: null,
            mountPath: null,
            engine: null,
            engineVersion: null,
            projectName: null,
            environmentName: null,
            environmentKind: null,
            serviceInstanceId: null,
            volumeId: null,
            state: $state,
            lastSucceededAt: $lastAt,
            lastSizeBytes: $success?->size_bytes,
            liveSizeBytes: null,
            nextRunAt: $next instanceof \DateTimeInterface ? CarbonImmutable::instance($next) : null,
            lastError: $isOwner && $state === BackupItemState::Failed ? $this->firstLine($finished?->error) : null,
            days: $this->days($runs['recent'], 'launchroom'),
            schedule: $schedule instanceof BackupSchedule ? new BackupItemScheduleData(
                $schedule->public_id,
                $schedule->cron,
                CronDescription::describe($schedule->cron),
                $schedule->retention->summary(),
                $schedule->enabled,
                1,
                $isOwner ? route('backups.schedules.update', $schedule->public_id) : null,
                $isOwner ? BackupScheduleData::fromSchedule($schedule, $this->statuses->forControlPlane(), true) : null,
            ) : null,
            run: $run,
            backUp: $backUp,
            volumeBackup: null,
            restoreHref: null,
            historyHref: null,
            storageRemoved: $schedule?->destination->trashed() ?? false,
        );

        $setup = match (true) {
            ! $isOwner => BackupActionData::blocked(BackupBlocker::OwnerOnly, 'Your launchroom’s owner sets this up.'),
            $configured => new BackupActionData(null),
            $destinations->isEmpty() => BackupActionData::blocked(BackupBlocker::NoStorage, 'Connect backup storage first. launchroom keeps its own backup in the same bucket.', 'Connect storage', '#setup'),
            default => BackupActionData::to(route('backups.control-plane.storage')),
        };

        $recent = $isOwner ? BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)->orderByDesc('id')->limit(10)->get() : new Collection;

        return new LaunchroomBackupData(
            isOwner: $isOwner,
            item: $item,
            destination: $isOwner && $configured ? BackupDestinationData::forPage($destination, self::count($destination), true, $this->overview->usage([$destination])[0] ?? null, $runs['destinationRuns'][$destination->id] ?? 0, false, canRevealPassword: true) : null,
            copiedFrom: $isOwner && $configured ? $this->copiedFrom($destination) : null,
            setup: $setup,
            runs: $isOwner ? array_values($recent->map(BackupRunData::fromRun(...))->all()) : [],
            recoveryKit: $isOwner ? $this->recoveryKit($settings, $configured, $passphrase) : null,
        );
    }

    private function recoveryKit(InstanceSettings $settings, bool $configured, bool $passphrase): RecoveryKitData
    {
        $downloaded = $settings->recovery_kit_downloaded_at;
        $confirmed = $settings->recovery_kit_confirmed_at;

        return new RecoveryKitData(
            step: match (true) {
                ! $passphrase => RecoveryKitStep::Passphrase,
                $downloaded === null => RecoveryKitStep::Download,
                $confirmed === null => RecoveryKitStep::KeepSafe,
                default => RecoveryKitStep::Done,
            },
            destinationConfigured: $configured,
            passphraseSet: $passphrase,
            passphraseSetAt: $settings->recovery_passphrase_set_at,
            kitDownloadedAt: $downloaded,
            kitConfirmedAt: $confirmed,
            needsAttention: ! $configured || ! $passphrase || $confirmed === null,
            passphraseUrl: route('backups.recovery.passphrase'),
            downloadUrl: route('backups.recovery.kit'),
            confirm: match (true) {
                ! $passphrase => BackupActionData::blocked(BackupBlocker::NoPassphrase, 'Set a recovery passphrase first.', 'Set passphrase', '#recovery-kit'),
                $downloaded === null => BackupActionData::blocked(BackupBlocker::KitNotDownloaded, 'Download the recovery kit first.'),
                default => BackupActionData::to(route('backups.recovery.confirm')),
            },
            resetReason: $confirmed === null ? $settings->recovery_kit_reset_reason : null,
        );
    }

    /**
     * @param  list<BackupItemData>  $items
     * @param  array<string, string>  $projects  project name => public id
     * @return list<BackupGroupData>
     */
    private function groups(array $items, ?BackupItemData $launchroom, array $projects): array
    {
        $groups = [];

        if ($launchroom instanceof BackupItemData) {
            $groups[] = new BackupGroupData('launchroom', 'launchroom', null, 'launchroom', [$launchroom]);
        }

        $byProject = [];

        foreach ($items as $item) {
            $byProject[$item->projectName ?? ''][] = $item;
        }

        ksort($byProject, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($byProject as $name => $list) {
            usort($list, static fn (BackupItemData $a, BackupItemData $b): int => [$a->state->rank(), $a->environmentName, $a->serviceName, $a->name] <=> [$b->state->rank(), $b->environmentName, $b->serviceName, $b->name]);
            $id = $projects[(string) $name] ?? null;
            $groups[] = new BackupGroupData($id ?? (string) $name, 'project', $id, (string) $name, $list);
        }

        return $groups;
    }

    /**
     * @param  list<BackupItemData>  $items
     */
    private function summary(array $items, bool $configured): BackupsSummaryData
    {
        $count = static fn (BackupItemState ...$states): int => count(array_filter($items, static fn (BackupItemData $item): bool => in_array($item->state, $states, true)));
        $attention = array_values(array_map(
            static fn (BackupItemData $item): BackupAttentionData => new BackupAttentionData($item->id, $item->serviceName === null || $item->kind !== BackupItemKind::Volume ? $item->name : "{$item->name} on {$item->serviceName}"),
            array_filter($items, static fn (BackupItemData $item): bool => in_array($item->state, [BackupItemState::Failed, BackupItemState::Overdue], true)),
        ));
        $last = null;
        $next = null;

        foreach ($items as $item) {
            $last = $item->lastSucceededAt instanceof CarbonInterface && (! $last instanceof CarbonInterface || $item->lastSucceededAt->greaterThan($last)) ? $item->lastSucceededAt : $last;
            $next = $item->nextRunAt instanceof CarbonInterface && (! $next instanceof CarbonInterface || $item->nextRunAt->lessThan($next)) ? $item->nextRunAt : $next;
        }

        $running = $count(BackupItemState::Running, BackupItemState::Queued);
        $failed = $count(BackupItemState::Failed);
        $overdue = $count(BackupItemState::Overdue);
        $waiting = $count(BackupItemState::Waiting);

        return new BackupsSummaryData(
            state: match (true) {
                ! $configured => BackupsSummaryState::Unconfigured,
                $running > 0 => BackupsSummaryState::Running,
                $failed + $overdue > 0 => BackupsSummaryState::Attention,
                $waiting > 0 => BackupsSummaryState::Waiting,
                default => BackupsSummaryState::Ok,
            },
            total: count($items),
            ok: $count(BackupItemState::Ok),
            running: $running,
            queued: $count(BackupItemState::Queued),
            waiting: $waiting,
            failed: $failed,
            overdue: $overdue,
            attention: $attention,
            lastSucceededAt: $last,
            nextRunAt: $next,
        );
    }

    /**
     * @param  Collection<int, BackupDestination>  $destinations
     * @param  array<int, int>  $runCounts
     * @return list<BackupDestinationData>
     */
    private function destinations(Collection $destinations, ?BackupDestination $instance, GateContract $gate, array $runCounts): array
    {
        $usage = [];

        foreach ($this->overview->usage(array_values($destinations->all())) as $row) {
            $usage[$row->destinationId] = $row;
        }

        return array_values($destinations->map(static fn (BackupDestination $destination): BackupDestinationData => BackupDestinationData::forPage(
            $destination,
            self::count($destination),
            $gate->allows('update', $destination),
            $usage[$destination->public_id] ?? null,
            $runCounts[$destination->id] ?? 0,
            $instance?->copied_from_id === $destination->id,
            $gate->allows('revealRepositoryPassword', $destination),
        ))->all());
    }

    /**
     * Removed storage that wasn't added again (a current storage at the same
     * bucket and folder took its history over).
     *
     * @param  Collection<int, BackupDestination>  $current
     * @return list<RemovedDestinationData>
     */
    private function removed(Team $team, Collection $current, GateContract $gate): array
    {
        $locations = $current->map(static fn (BackupDestination $d): array => BackupDestination::location($d->endpoint, $d->bucket, $d->prefix))->all();
        $removed = BackupDestination::onlyTrashed()->where('team_id', $team->id)->latest('removed_at')->limit(10)->get()
            ->reject(static fn (BackupDestination $d): bool => in_array(BackupDestination::location($d->endpoint, $d->bucket, $d->prefix), $locations, true))
            ->values();

        if ($removed->isEmpty()) {
            return [];
        }

        $stats = BackupRun::query()->whereIn('destination_id', $removed->modelKeys())->groupBy('destination_id')
            ->selectRaw('destination_id, count(*) as runs, max(finished_at) as last')->get()->keyBy('destination_id');

        return array_values($removed->map(static function (BackupDestination $destination) use ($stats, $gate): RemovedDestinationData {
            $row = $stats->get($destination->id);
            $last = $row?->getAttribute('last');

            return new RemovedDestinationData(
                $destination->public_id,
                $destination->name,
                $destination->endpoint,
                $destination->bucket,
                $destination->prefix,
                $destination->removed_at,
                is_numeric($row?->getAttribute('runs')) ? (int) $row->getAttribute('runs') : 0,
                is_string($last) ? CarbonImmutable::parse($last) : null,
                $destination->keepsRepositoryPassword(),
                $destination->keepsRepositoryPassword() && $gate->allows('revealRepositoryPassword', $destination)
                    ? route('backups.destinations.repository-password', $destination->public_id) : null,
            );
        })->all());
    }

    /**
     * @param  list<BackupItemData>  $items
     */
    private function batch(Team $team, array $items): ?BackupBatchProgressData
    {
        $latest = BackupBatches::latest($team);

        if ($latest === null) {
            return null;
        }

        $runs = BackupRun::query()->where('batch_id', $latest['id'])->get(['id', 'subject', 'service_instance_id', 'volume_id', 'status', 'error', 'finished_at']);
        $open = $runs->reject(static fn (BackupRun $run): bool => $run->status->isTerminal());
        $finishedAt = $open->isEmpty() ? $runs->max('finished_at') : null;
        $finishedAt = $finishedAt instanceof CarbonInterface ? $finishedAt : ($open->isEmpty() ? CarbonImmutable::createFromTimestamp($latest['at']) : null);

        if ($finishedAt instanceof CarbonInterface && $finishedAt->lt(now()->subSeconds(self::BATCH_VISIBLE_SECONDS))) {
            return null;
        }

        $names = [];

        foreach ($items as $item) {
            $names[$item->id] = $item->kind === BackupItemKind::Volume && $item->serviceName !== null ? "{$item->name} on {$item->serviceName}" : $item->name;
        }

        $failed = $runs->where('status', BackupRunStatus::Failed);
        $volumes = Volume::query()->whereIn('id', $failed->pluck('volume_id')->filter()->all())->pluck('public_id', 'id');
        $instances = ServiceInstance::query()->whereIn('id', $failed->pluck('service_instance_id')->filter()->all())->pluck('public_id', 'id');
        $failures = [];

        foreach ($failed as $run) {
            $id = match ($run->subject) {
                BackupSubject::ControlPlane => 'launchroom',
                BackupSubject::Volume => 'volume:'.$this->string($volumes->get($run->volume_id ?? 0)),
                default => 'database:'.$this->string($instances->get($run->service_instance_id ?? 0)),
            };
            $failures[] = ['itemId' => $id, 'name' => $names[$id] ?? 'a backup', 'error' => $this->firstLine($run->error)];
        }

        return new BackupBatchProgressData(
            id: $latest['id'],
            startedAt: CarbonImmutable::createFromTimestamp($latest['at']),
            finishedAt: $finishedAt,
            total: $runs->count(),
            succeeded: $runs->where('status', BackupRunStatus::Succeeded)->count(),
            failed: count($failures),
            running: $open->where('status', BackupRunStatus::Running)->count(),
            queued: $open->where('status', BackupRunStatus::Pending)->count(),
            refused: $latest['refused'],
            failures: $failures,
        );
    }

    /**
     * @param  list<BackupItemData>  $items
     */
    private function everythingAction(array $items, bool $canDeploy, bool $hasStorage, ?BackupBatchProgressData $batch): BackupActionData
    {
        $startable = array_filter($items, static fn (BackupItemData $item): bool => $item->backUp->url !== null);

        return match (true) {
            ! $canDeploy => BackupActionData::blocked(BackupBlocker::NoPermission, 'You need deploy access to start backups.'),
            ! $hasStorage && $startable === [] => BackupActionData::blocked(BackupBlocker::NoStorage, 'Connect backup storage to start.', 'Connect storage', '#setup'),
            $batch instanceof BackupBatchProgressData && ! $batch->finishedAt instanceof CarbonInterface => BackupActionData::blocked(BackupBlocker::Running, 'Backing up '.($batch->succeeded + $batch->failed).' of '.$batch->total.'.'),
            $startable === [] => BackupActionData::blocked(BackupBlocker::NothingToBackUp, 'Everything is already backing up, or nothing can be backed up right now.'),
            default => BackupActionData::to(route('backups.everything')),
        };
    }
}

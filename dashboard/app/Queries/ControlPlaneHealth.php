<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Instance\System\SystemCheckData;
use App\Data\Instance\System\SystemEventData;
use App\Data\Instance\System\SystemFailedJobData;
use App\Data\Instance\System\SystemHealthData;
use App\Data\Instance\System\SystemQueueData;
use App\Data\Instance\System\SystemServiceData;
use App\Data\Servers\ControlPlaneHealthData;
use App\Enums\Backups\BackupHealth;
use App\Enums\Instance\SystemCheckStatus;
use App\Enums\ServerStatus;
use App\Enums\Updates\ControlPlaneUpdateStatus;
use App\Models\Backups\BackupDestination;
use App\Models\DashboardDomainChange;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Updates\ControlPlaneUpdate;
use App\Queries\Backups\BackupStatuses;
use App\Services\Moves\InstanceFreeze;
use App\Services\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * How the control plane itself is doing (G31, G34, G41): readiness for
 * `/up/ready`, the checks of `launchroom:doctor --json` and Settings →
 * System. Every probe is bounded and catches its own failure: a part that
 * can't be read is reported as unknown (or as the failing check), never
 * thrown, because this is what people open when things are broken.
 */
final readonly class ControlPlaneHealth
{
    /** Jobs waiting longer than this make the queue check warn. */
    public const int QUEUE_WAIT_WARN_SECONDS = 300;

    /** The main server's launchroomd reports every 10–30 s. */
    public const int SERVER_SILENT_SECONDS = 120;

    /** Free space on the main server below this share warns, half of it fails. */
    public const float DISK_WARN_RATIO = 0.10;

    /** The dashboard's certificate renews 30 days ahead; this close means renewal keeps failing. */
    public const int CERTIFICATE_WARN_DAYS = 14;

    private const array SERVICE_LABELS = [
        'dashboard' => 'Dashboard',
        'horizon' => 'Background jobs',
        'reverb' => 'Live updates',
        'scheduler' => 'Scheduler',
        'zot' => 'Image registry',
        'postgres' => 'Database',
        'valkey' => 'Cache',
    ];

    private const array RESTART_REASONS = [
        'unhealthy' => 'its health check failed 3 times',
        'not_ready' => 'it stopped responding',
        'exited' => 'it stopped unexpectedly',
    ];

    private const array QUEUE_LABELS = [
        'default' => 'Deploys and everything else',
        'alerts' => 'Notifications',
        'long' => 'Backups, updates and image copies',
    ];

    public function __construct(
        private BackupStatuses $backups,
        private Migrator $migrator,
        private SchedulerHeartbeat $heartbeat,
    ) {}

    /**
     * `/up/ready`: the database and the cache answer within about a second.
     *
     * @return array{database: bool, cache: bool}
     */
    public function ready(): array
    {
        return ['database' => $this->databaseAnswers(), 'cache' => $this->cacheAnswers()];
    }

    /**
     * The checks, in the order `./launchroom doctor` prints them.
     *
     * @return list<SystemCheckData>
     */
    public function checks(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $server = $this->server();

        return [
            $this->migrationsCheck(),
            $this->workersCheck(),
            ...$this->queueChecks(),
            $this->schedulerCheck($now),
            $this->locksCheck(),
            ...$this->backupChecks($now),
            $this->serverCheck($server, $now),
            $this->diskCheck($server),
            $this->fleetCheck(),
            $this->certificateCheck($now),
            $this->changesCheck(),
            $this->updateCheck($now),
        ];
    }

    public function page(bool $canViewHorizon, ?CarbonImmutable $now = null): SystemHealthData
    {
        $now ??= CarbonImmutable::now();
        $server = $this->server();
        $watchdog = $server?->control_plane_health instanceof ControlPlaneHealthData ? $server->control_plane_health : null;
        $beat = $this->heartbeat->last();
        $backup = $this->backups->forControlPlane();
        $certificate = $this->dashboardCertificate();
        $host = $server?->host_status;

        return new SystemHealthData(
            checkedAt: $now->toIso8601ZuluString(),
            checks: $this->checks($now),
            services: $watchdog instanceof ControlPlaneHealthData ? array_map(static fn (array $service): SystemServiceData => new SystemServiceData(
                name: $service['name'],
                label: self::SERVICE_LABELS[$service['name']] ?? $service['name'],
                state: $service['state'],
                startedAt: $service['startedAt'],
                restarts: $service['restarts'],
                oomKilled: $service['oomKilled'],
            ), $watchdog->services) : null,
            servicesCheckedAt: $watchdog?->checkedAt,
            ready: $watchdog?->ready,
            events: $watchdog instanceof ControlPlaneHealthData ? $this->events($watchdog) : [],
            workers: $this->workers(),
            queues: $this->queues(),
            failedJobs: $this->failedJobs($now),
            schedulerBeatAt: $beat === null ? null : $beat['at']->toIso8601ZuluString(),
            staleLocks: $beat['staleLocks'] ?? [],
            diskFreeMb: $host?->volumeDiskFreeMb(),
            diskTotalMb: $host?->volumeDiskTotalMb(),
            registryMb: $host?->health?->disk?->registryMb,
            databaseBytes: $this->databaseBytes(),
            cacheBytes: $this->cacheBytes(),
            backupStatus: $backup->status->value,
            backupLastSuccessAt: $backup->lastSuccessfulAt?->toIso8601ZuluString(),
            backupNextRunAt: $backup->nextRunAt?->toIso8601ZuluString(),
            certificateHost: $certificate['host'] ?? null,
            certificateExpiresAt: isset($certificate['expires']) ? $certificate['expires']->toIso8601ZuluString() : null,
            version: config()->string('launchroom.version'),
            serverVersion: $server?->daemon_version,
            databaseVersion: $this->databaseVersion(),
            cacheVersion: $this->cacheVersion(),
            horizonUrl: $canViewHorizon ? '/'.trim(config()->string('horizon.path'), '/') : null,
        );
    }

    /**
     * The dashboard domain's certificate once the dashboard was switched to
     * it (G38), from the main server's edge reports.
     *
     * @return array{host: string, expires: CarbonImmutable|null}|null
     */
    public function dashboardCertificate(): ?array
    {
        $active = DashboardDomainChange::active();

        if (! $active instanceof DashboardDomainChange) {
            return null;
        }

        $notAfter = $active->certificate?->notAfter;

        return ['host' => $active->hostname, 'expires' => is_string($notAfter) ? CarbonImmutable::parse($notAfter) : null];
    }

    private function server(): ?Server
    {
        try {
            return Server::currentControlPlane();
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseAnswers(): bool
    {
        try {
            return DB::transaction(static function (): bool {
                // SET LOCAL ends with the transaction: a worker that serves
                // the next request keeps its normal statement timeout.
                DB::statement("SET LOCAL statement_timeout = '1s'");

                return DB::selectOne('select 1 as ok') !== null;
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheAnswers(): bool
    {
        try {
            if (config()->string('cache.default') === 'redis') {
                return $this->redis(static fn (Connection $connection): bool => $connection->command('ping') !== false);
            }

            Cache::put('launchroom:ready-probe', 1, 10);

            return Cache::get('launchroom:ready-probe') === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Runs $probe on a short-lived connection to the cache's Valkey with
     * 1-second timeouts and no retries, so a hung Valkey answers "no" in
     * time instead of hanging the request.
     *
     * @template T
     *
     * @param  callable(Connection): T  $probe
     * @return T
     */
    private function redis(callable $probe): mixed
    {
        $name = config()->string('cache.stores.redis.connection', 'cache');
        /** @var array<string, mixed> $redis */
        $redis = (array) config('database.redis');
        $connection = is_array($redis[$name] ?? null) ? $redis[$name] : (array) ($redis['default'] ?? []);
        $client = is_string($redis['client'] ?? null) ? $redis['client'] : 'phpredis';
        $manager = new RedisManager(app(), $client, [
            'options' => is_array($redis['options'] ?? null) ? $redis['options'] : [],
            'probe' => [...$connection, 'timeout' => 1, 'read_timeout' => 1, 'max_retries' => 0, 'persistent' => false],
        ]);

        try {
            return $probe($manager->connection('probe'));
        } finally {
            $manager->purge('probe');
        }
    }

    private function migrationsCheck(): SystemCheckData
    {
        try {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return $this->check('migrations', 'Database schema', SystemCheckStatus::Fail, 'The database has no migration table. launchroom was never set up on it, or it was emptied.');
            }

            $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
            $pending = array_values(array_diff(array_keys($files), $repository->getRan()));
        } catch (Throwable) {
            return $this->check('migrations', 'Database schema', SystemCheckStatus::Fail, 'The database can’t be read.');
        }

        return $pending === []
            ? $this->check('migrations', 'Database schema', SystemCheckStatus::Ok, 'Up to date.')
            : $this->check('migrations', 'Database schema', SystemCheckStatus::Fail, count($pending).' database changes of this version haven’t run. The dashboard runs them when it starts; a failed update leaves them pending.', array_slice($pending, 0, 5));
    }

    private function workersCheck(): SystemCheckData
    {
        return match ($this->workers()) {
            'running' => $this->check('workers', 'Background jobs', SystemCheckStatus::Ok, 'The workers are running.'),
            'paused' => $this->check('workers', 'Background jobs', SystemCheckStatus::Warn, 'The workers are paused: deploys, backups and notifications wait until they continue.'),
            'stopped' => $this->check('workers', 'Background jobs', SystemCheckStatus::Fail, 'No workers are running: deploys, backups and notifications wait.'),
            default => $this->check('workers', 'Background jobs', SystemCheckStatus::Skip, 'The workers’ state can’t be read while the cache is down.'),
        };
    }

    /**
     * @return list<SystemCheckData>
     */
    private function queueChecks(): array
    {
        $queues = $this->queues();
        $checks = [];

        if ($queues === null) {
            $checks[] = $this->check('queues', 'Queues', SystemCheckStatus::Skip, 'The queues can’t be read while the cache is down.');
        } else {
            $slow = array_values(array_filter($queues, static fn (SystemQueueData $queue): bool => $queue->waitSeconds > self::QUEUE_WAIT_WARN_SECONDS));
            $waiting = array_sum(array_map(static fn (SystemQueueData $queue): int => $queue->waiting, $queues));

            $checks[] = $slow === []
                ? $this->check('queues', 'Queues', SystemCheckStatus::Ok, $waiting === 0 ? 'Nothing is waiting.' : "{$waiting} jobs are waiting and none of them for long.")
                : $this->check(
                    'queues', 'Queues', SystemCheckStatus::Warn,
                    'Jobs wait longer than usual. If the workers are stuck, restarting them lets each finish its current job first.',
                    array_map(static fn (SystemQueueData $queue): string => "{$queue->label}: {$queue->waiting} waiting, about ".max(1, intdiv($queue->waitSeconds, 60)).' min', $slow),
                    $this->workers() === 'running' ? SystemCheckData::FIX_RESTART_WORKERS : null,
                );
        }

        $failed = $this->failedJobs(CarbonImmutable::now());

        if ($failed === null) {
            $checks[] = $this->check('jobs.failed', 'Failed jobs', SystemCheckStatus::Skip, 'Failed jobs can’t be read.');
        } elseif ($failed === []) {
            $checks[] = $this->check('jobs.failed', 'Failed jobs', SystemCheckStatus::Ok, 'None in the last 24 hours.');
        } else {
            $total = array_sum(array_map(static fn (SystemFailedJobData $job): int => $job->count, $failed));
            $checks[] = $this->check('jobs.failed', 'Failed jobs', SystemCheckStatus::Warn, "{$total} ".($total === 1 ? 'job' : 'jobs').' failed in the last 24 hours. Background jobs in Settings → System shows why.',
                array_map(static fn (SystemFailedJobData $job): string => "{$job->job}: {$job->count}", $failed));
        }

        return $checks;
    }

    private function schedulerCheck(CarbonImmutable $now): SystemCheckData
    {
        $beat = $this->heartbeat->last();

        if ($beat === null) {
            return $this->check('scheduler', 'Scheduler', SystemCheckStatus::Fail, 'The scheduler hasn’t run yet, or its heartbeat can’t be read. Backups, sweeps and alerts don’t run without it.');
        }

        $minutes = intdiv((int) $beat['at']->diffInSeconds($now, true), 60);

        return $this->heartbeat->alive($now)
            ? $this->check('scheduler', 'Scheduler', SystemCheckStatus::Ok, 'It ran less than a minute ago.')
            : $this->check('scheduler', 'Scheduler', SystemCheckStatus::Fail, "It last ran {$minutes} minutes ago. Backups, sweeps and alerts don’t run until it’s back.");
    }

    private function locksCheck(): SystemCheckData
    {
        $stale = $this->heartbeat->last()['staleLocks'] ?? [];

        return $stale === []
            ? $this->check('schedule.locks', 'Scheduled commands', SystemCheckStatus::Ok, 'None is held up.')
            : $this->check('schedule.locks', 'Scheduled commands', SystemCheckStatus::Fail,
                count($stale).' scheduled '.(count($stale) === 1 ? 'command is' : 'commands are').' locked by a run that stopped. They run again when the lock expires, or right away once the locks are cleared.',
                $stale, SystemCheckData::FIX_CLEAR_SCHEDULE_LOCKS);
    }

    /**
     * @return list<SystemCheckData>
     */
    private function backupChecks(CarbonImmutable $now): array
    {
        try {
            $status = $this->backups->forControlPlane();
            $settings = InstanceSettings::current();
            $destination = BackupDestination::forInstance();
        } catch (Throwable) {
            return [$this->check('backup', 'Control-plane backup', SystemCheckStatus::Skip, 'The backup state can’t be read.')];
        }

        $last = $status->lastSuccessfulAt;
        $ago = $last instanceof CarbonInterface ? $this->ago($last, $now) : null;

        $backup = match ($status->status) {
            BackupHealth::Unconfigured => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Warn, 'Off: without a backup destination, losing this server loses every project’s settings. Set it up on the Backups page.'),
            BackupHealth::Failing => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Fail, 'The latest backup failed'.($ago === null ? '.' : ", the last good one is {$ago} old."), $status->lastError === null ? [] : [mb_substr($status->lastError, 0, 200)]),
            BackupHealth::Stale => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Warn, 'No backup succeeded for '.($ago ?? 'a long time').'.'),
            BackupHealth::None => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Ok, 'Scheduled; no backup has run yet.'),
            BackupHealth::Running => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Ok, 'A backup is running.'),
            BackupHealth::Ok => $this->check('backup', 'Control-plane backup', SystemCheckStatus::Ok, 'The last backup is '.($ago ?? 'recent').' old.'),
        };

        if (! $destination instanceof BackupDestination) {
            return [$backup];
        }

        return [$backup, $settings->recovery_kit_confirmed_at === null
            ? $this->check('recovery-kit', 'Recovery kit', SystemCheckStatus::Warn, 'Not saved yet. Without it, a backup can’t be restored on a new server. Download it on the Backups page.')
            : $this->check('recovery-kit', 'Recovery kit', SystemCheckStatus::Ok, 'Saved.')];
    }

    private function serverCheck(?Server $server, CarbonImmutable $now): SystemCheckData
    {
        if (! $server instanceof Server) {
            return $this->check('server', 'This server', SystemCheckStatus::Warn, 'The server launchroom runs on isn’t connected. Apps can’t run on it until it is.');
        }

        $seen = $server->last_seen_at;

        if ($seen === null || $seen->lessThan($now->subSeconds(self::SERVER_SILENT_SECONDS))) {
            return $this->check('server', 'This server', SystemCheckStatus::Fail, $seen === null
                ? 'This server never checked in.'
                : 'This server last checked in '.$this->ago($seen, $now).' ago.');
        }

        return $this->check('server', 'This server', SystemCheckStatus::Ok, 'It checks in.');
    }

    private function diskCheck(?Server $server): SystemCheckData
    {
        $host = $server?->host_status;
        $total = $host?->volumeDiskTotalMb();

        if ($host === null || $total === null) {
            return $this->check('disk', 'Disk', SystemCheckStatus::Skip, 'Not reported yet.');
        }

        $free = $host->volumeDiskFreeMb();
        $summary = 'Free: '.$this->size($free).' of '.$this->size($total).'.';

        return match (true) {
            $free < $total * self::DISK_WARN_RATIO / 2 => $this->check('disk', 'Disk', SystemCheckStatus::Fail, $summary.' Builds and backups fail when it runs out.'),
            $free < $total * self::DISK_WARN_RATIO => $this->check('disk', 'Disk', SystemCheckStatus::Warn, $summary.' Space is running low.'),
            default => $this->check('disk', 'Disk', SystemCheckStatus::Ok, $summary),
        };
    }

    private function fleetCheck(): SystemCheckData
    {
        try {
            /** @var list<string> $offline */
            $offline = Server::query()->notRevoked()->whereIn('status', [ServerStatus::Offline->value, ServerStatus::Unreachable->value])->orderBy('name')->pluck('name')->values()->all();
        } catch (Throwable) {
            return $this->check('servers', 'Servers', SystemCheckStatus::Skip, 'The servers can’t be read.');
        }

        return $offline === []
            ? $this->check('servers', 'Servers', SystemCheckStatus::Ok, 'All servers are connected.')
            : $this->check('servers', 'Servers', SystemCheckStatus::Warn, count($offline).' '.(count($offline) === 1 ? 'server doesn’t' : 'servers don’t').' check in.', array_slice($offline, 0, 10));
    }

    private function certificateCheck(CarbonImmutable $now): SystemCheckData
    {
        $certificate = $this->dashboardCertificate();

        if ($certificate === null) {
            return $this->check('certificate', 'Dashboard certificate', SystemCheckStatus::Skip, 'The dashboard runs at its first address.');
        }

        $expires = $certificate['expires'];

        if (! $expires instanceof CarbonImmutable) {
            return $this->check('certificate', 'Dashboard certificate', SystemCheckStatus::Warn, "No certificate for {$certificate['host']} was reported yet.");
        }

        $days = (int) floor($now->diffInSeconds($expires, false) / 86_400);

        return match (true) {
            $expires->lessThanOrEqualTo($now) => $this->check('certificate', 'Dashboard certificate', SystemCheckStatus::Fail, "The certificate for {$certificate['host']} expired on {$expires->format('j M Y')}."),
            $days < self::CERTIFICATE_WARN_DAYS => $this->check('certificate', 'Dashboard certificate', SystemCheckStatus::Warn, "The certificate for {$certificate['host']} expires in {$days} ".($days === 1 ? 'day' : 'days').' and renewing it keeps failing. Check that the domain still points at this server.'),
            default => $this->check('certificate', 'Dashboard certificate', SystemCheckStatus::Ok, "Valid until {$expires->format('j M Y')}."),
        };
    }

    private function changesCheck(): SystemCheckData
    {
        try {
            if (InstanceFreeze::active()) {
                return $this->check('changes', 'Changes', SystemCheckStatus::Warn, 'Paused: launchroom is moving to another server, or moved there.');
            }

            $update = ControlPlaneUpdate::query()->latest('id')->first();
        } catch (Throwable) {
            return $this->check('changes', 'Changes', SystemCheckStatus::Skip, 'Can’t be read.');
        }

        return $update instanceof ControlPlaneUpdate && ! $update->status->isTerminal()
            ? $this->check('changes', 'Changes', SystemCheckStatus::Ok, "An update to {$update->to_version} is running.")
            : $this->check('changes', 'Changes', SystemCheckStatus::Ok, 'Nothing is paused.');
    }

    /**
     * An update that shows no sign of life (docs/11 §4.3), also when the
     * dashboard's pages are down: the fix cancels it (nothing has changed
     * yet, so it is safe), then the owner tries again.
     */
    private function updateCheck(CarbonImmutable $now): SystemCheckData
    {
        try {
            $update = ControlPlaneUpdate::query()->with(['backupRun', 'daemonTask'])->whereIn('status', ControlPlaneUpdateStatus::nonTerminal())->latest('id')->first();
        } catch (Throwable) {
            return $this->check('update', 'Update', SystemCheckStatus::Skip, 'Can’t be read.');
        }

        if (! $update instanceof ControlPlaneUpdate) {
            return $this->check('update', 'Update', SystemCheckStatus::Ok, 'No update is running.');
        }

        $last = $update->lastActivityAt();

        if (! $update->stalled($now) || ! $last instanceof CarbonInterface) {
            return $this->check('update', 'Update', SystemCheckStatus::Ok, "The update to {$update->to_version} is running ({$update->status->label()}).");
        }

        return $update->cancellable()
            ? $this->check('update', 'Update', SystemCheckStatus::Fail, "The update to {$update->to_version} has made no progress for ".$this->ago($last, $now).'. Nothing has changed yet, so it can be cancelled and tried again.', [$update->status->label()], SystemCheckData::FIX_CANCEL_UPDATE)
            : $this->check('update', 'Update', SystemCheckStatus::Warn, "The update to {$update->to_version} has made no progress for ".$this->ago($last, $now).'.', [$update->status->label()]);
    }

    /**
     * Horizon's state (null: the cache can't be read). Also for the
     * `queue_backlog` and `control_plane_degraded` alert rules.
     *
     * @return 'running'|'paused'|'stopped'|null
     */
    public function workers(): ?string
    {
        try {
            $masters = resolve(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return null;
        }

        $statuses = array_map(static fn (mixed $master): mixed => is_object($master) ? ($master->status ?? null) : null, $masters);

        return match (true) {
            in_array('running', $statuses, true) => 'running',
            $statuses !== [] => 'paused',
            default => 'stopped',
        };
    }

    /**
     * Every queue Horizon serves, with what waits and for how long (null:
     * the cache can't be read). Also for the `queue_backlog` alert rule.
     *
     * @return list<SystemQueueData>|null
     */
    public function queues(): ?array
    {
        try {
            $workload = resolve(WorkloadRepository::class)->get();
        } catch (Throwable) {
            return null;
        }

        $queues = [];

        foreach ($workload as $queue) {
            $queues[] = new SystemQueueData(
                name: $queue['name'],
                label: self::QUEUE_LABELS[$queue['name']] ?? $queue['name'],
                waiting: $queue['length'],
                waitSeconds: (int) $queue['wait'],
                workers: $queue['processes'],
            );
        }

        return $queues;
    }

    /**
     * Failed jobs of the last 24 hours, by class.
     *
     * @return list<SystemFailedJobData>|null
     */
    private function failedJobs(CarbonImmutable $now): ?array
    {
        try {
            $rows = DB::table(config()->string('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>', $now->subDay())
                ->latest('failed_at')
                ->limit(500)
                ->get(['payload', 'failed_at']);
        } catch (Throwable) {
            return null;
        }

        $byJob = [];

        foreach ($rows as $row) {
            $payload = is_string($row->payload ?? null) ? json_decode($row->payload, true) : null;
            $name = is_array($payload) && is_string($payload['displayName'] ?? null) ? class_basename($payload['displayName']) : 'Unknown job';
            $byJob[$name] ??= ['count' => 0, 'at' => is_string($row->failed_at ?? null) ? $row->failed_at : CarbonImmutable::now()->toIso8601ZuluString()];
            $byJob[$name]['count']++;
        }

        $out = [];

        foreach (array_slice($byJob, 0, 10, true) as $name => $job) {
            $out[] = new SystemFailedJobData(job: (string) $name, count: $job['count'], lastFailedAt: CarbonImmutable::parse($job['at'])->toIso8601ZuluString());
        }

        return $out;
    }

    /**
     * @return list<SystemEventData>
     */
    private function events(ControlPlaneHealthData $watchdog): array
    {
        $events = [];

        foreach ($watchdog->restarts as $restart) {
            $label = self::SERVICE_LABELS[$restart['service']] ?? $restart['service'];
            $reason = self::RESTART_REASONS[$restart['reason']] ?? 'it wasn’t working';
            $events[] = new SystemEventData(at: $restart['at'], text: "launchroom restarted {$label}: {$reason}.");
        }

        foreach ($watchdog->notices as $notice) {
            $what = match ($notice['kind']) {
                'down' => 'the dashboard can’t be reached',
                'still_down' => 'the dashboard still can’t be reached',
                default => 'the dashboard is back',
            };
            $events[] = new SystemEventData(
                at: $notice['at'],
                text: $notice['sent'] ? "Sent the fallback notification: {$what}." : "Couldn’t send the fallback notification ({$what})".($notice['error'] === null ? '.' : ": {$notice['error']}"),
                failed: ! $notice['sent'],
            );
        }

        usort($events, static fn (SystemEventData $a, SystemEventData $b): int => strcmp($b->at, $a->at));

        return array_slice($events, 0, 20);
    }

    private function databaseBytes(): ?int
    {
        try {
            $size = DB::selectOne('select pg_database_size(current_database()) as size');
        } catch (Throwable) {
            return null;
        }

        return is_object($size) && is_numeric($size->size ?? null) ? (int) $size->size : null;
    }

    private function databaseVersion(): ?string
    {
        try {
            $version = DB::selectOne('show server_version');
        } catch (Throwable) {
            return null;
        }

        return is_object($version) && is_string($version->server_version ?? null) ? strtok($version->server_version, ' ') ?: null : null;
    }

    private function cacheBytes(): ?int
    {
        $info = $this->cacheInfo();

        return is_numeric($info['used_memory'] ?? null) ? (int) $info['used_memory'] : null;
    }

    private function cacheVersion(): ?string
    {
        $info = $this->cacheInfo();

        return match (true) {
            is_string($info['valkey_version'] ?? null) => $info['valkey_version'],
            is_string($info['redis_version'] ?? null) => $info['redis_version'],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheInfo(): array
    {
        if (config()->string('cache.default') !== 'redis') {
            return [];
        }

        try {
            $info = $this->redis(static fn (Connection $connection): array => [...(array) $connection->command('info', ['server']), ...(array) $connection->command('info', ['memory'])]);
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($info as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  list<string>  $detail
     */
    private function check(string $id, string $title, SystemCheckStatus $status, string $summary, array $detail = [], ?string $fix = null): SystemCheckData
    {
        return new SystemCheckData(id: $id, title: $title, status: $status, summary: $summary, detail: $detail, fix: $fix);
    }

    private function ago(CarbonInterface $then, CarbonImmutable $now): string
    {
        $minutes = max(1, intdiv((int) $then->diffInSeconds($now, true), 60));

        return match (true) {
            $minutes < 120 => $minutes.' minutes',
            $minutes < 2_880 => intdiv($minutes, 60).' hours',
            default => intdiv($minutes, 1_440).' days',
        };
    }

    private function size(int $mb): string
    {
        return $mb >= 10_240 ? round($mb / 1_024).' GB' : ($mb >= 1_024 ? round($mb / 1_024, 1).' GB' : $mb.' MB');
    }
}

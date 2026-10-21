<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\DatabaseEngine;
use App\Enums\EnvironmentKind;
use App\Models\Backups\BackupRun;
use App\Models\Backups\WalArchive;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Services\Crypto\ProjectKeyring;
use App\Support\Crypto\WorkloadEnvSealer;
use Carbon\CarbonImmutable;

/**
 * Postgres point-in-time recovery (docs/14 §10): the layout launchroomd
 * relies on, the args and `walArchive` rendered into a NodeState, the WAL
 * snapshot tags and the window a time can be picked from.
 *
 * Postgres archives completed WAL segments with its bundled basic_archive
 * module (`archive_library`, no `archive_command` and so no shell string)
 * into `<volume>/launchroom-wal`; launchroomd ships them into the backup
 * repository every 30 s; Postgres switches segments at least every minute
 * while there is activity (`archive_timeout`). RPO: about a minute.
 */
final readonly class PointInTimeRecovery
{
    /** The WAL archive directory's name inside the volume (launchroomd's backup.WalDirName). */
    public const string ARCHIVE_DIR = 'launchroom-wal';

    public const int ARCHIVE_TIMEOUT_SECONDS = 60;

    public const string SHIP_INTERVAL = '30s';

    /** basic_archive ships with Postgres 15+. */
    public const int MIN_VERSION = 15;

    public function __construct(
        private DatabaseEngines $engines,
        private DestinationSecrets $secrets,
        private ProjectKeyring $keyring,
    ) {}

    /**
     * Whether the instance can have point-in-time recovery: Postgres 15+ in
     * a persistent environment (previews are thrown away).
     */
    public function supports(ServiceInstance $instance): bool
    {
        $instance->loadMissing(['service', 'environment']);
        $source = DatabaseWorkloads::source($instance);

        return $source instanceof DatabaseSource
            && $source->engine === DatabaseEngine::Postgres
            && (int) $source->version >= self::MIN_VERSION
            && $instance->environment->kind !== EnvironmentKind::Preview;
    }

    public function mountPath(string $version): string
    {
        return $this->engines->for(DatabaseEngine::Postgres)->volumeMountPath($version);
    }

    /**
     * PGDATA of the official images: versioned below the volume from 18 on.
     */
    public function dataDirectory(string $version): string
    {
        return (int) $version >= 18 ? "/var/lib/postgresql/{$version}/docker" : '/var/lib/postgresql/data';
    }

    public function archiveDirectory(string $version): string
    {
        return $this->mountPath($version).'/'.self::ARCHIVE_DIR;
    }

    /**
     * The workload's args with archiving on, or null when its start command
     * is not Postgres itself (then archiving can't be added safely).
     *
     * @param  list<string>|null  $args
     * @return list<string>|null
     */
    public function archivingArgs(?array $args, string $version): ?array
    {
        $args ??= ['postgres'];

        if (($args[0] ?? null) !== 'postgres') {
            return null;
        }

        return [
            ...$args,
            '-c', 'archive_mode=on',
            '-c', 'archive_library=basic_archive',
            '-c', 'basic_archive.archive_directory='.$this->archiveDirectory($version),
            '-c', 'archive_timeout='.self::ARCHIVE_TIMEOUT_SECONDS,
        ];
    }

    /**
     * The enabled archive of an instance, if any.
     */
    public function enabledFor(ServiceInstance $instance): ?WalArchive
    {
        $archive = WalArchive::query()->where('service_instance_id', $instance->id)->where('enabled', true)->first();

        return $archive instanceof WalArchive && $this->supports($instance) ? $archive : null;
    }

    /**
     * NodeState `walArchive` of a workload (credentials sealed to the
     * server and workload as a WorkloadEnv) and its stable fingerprint.
     *
     * @return array{spec: array<string, mixed>, fingerprint: string}
     */
    public function walArchiveSpec(WalArchive $archive, Server $server, string $workloadId, string $version): array
    {
        $archive->loadMissing(['destination', 'serviceInstance.service', 'serviceInstance.environment']);
        $destination = $archive->destination;
        $credentials = $this->secrets->reveal($destination);
        $sealed = WorkloadEnvSealer::seal($server->sealing_public_key, $server->public_id, $workloadId, [
            'AWS_ACCESS_KEY_ID' => $credentials->accessKeyId,
            'AWS_SECRET_ACCESS_KEY' => $credentials->secretAccessKey,
            'RESTIC_PASSWORD' => $credentials->repositoryPassword,
        ]);

        $spec = [
            'directory' => $this->archiveDirectory($version),
            'destination' => array_filter([
                'kind' => 's3',
                'endpoint' => $destination->endpoint,
                'bucket' => $destination->bucket,
                'region' => $destination->region,
                'prefix' => trim($destination->prefix, '/') === '' ? null : trim($destination->prefix, '/'),
                'credentials' => ['sealed' => $sealed],
            ], static fn (mixed $value): bool => $value !== null),
            'tags' => $this->walTags($archive->serviceInstance, withIdentity: true),
            'interval' => self::SHIP_INTERVAL,
        ];

        // Changes when the destination or its secrets change (secret-free).
        $fingerprint = hash('sha256', implode('|', [
            $destination->public_id, $destination->endpoint, $destination->bucket, $destination->region ?? '', $destination->prefix,
            hash('sha256', $this->raw($destination->getRawOriginal('credentials'))), hash('sha256', $this->raw($destination->getRawOriginal('repository_password'))),
        ]));

        return ['spec' => $spec, 'fingerprint' => $fingerprint];
    }

    private function raw(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Tags of the instance's WAL snapshots: the filter launchroomd selects
     * them by, plus (when shipping) where they come from.
     *
     * @return list<string>
     */
    public function walTags(ServiceInstance $instance, bool $withIdentity = false): array
    {
        $instance->loadMissing(['service.project.team', 'environment.project']);
        $tags = ['kind:wal', 'environment:'.$instance->environment->public_id, 'service:'.$instance->service->public_id];

        return $withIdentity ? [
            'instance:'.SnapshotTags::instanceId(),
            ...$tags,
            'team:'.$instance->service->project->team->public_id,
            'project:'.$instance->environment->project->public_id,
        ] : $tags;
    }

    /**
     * Tags every base backup of the instance carries (listing, retention).
     *
     * @return list<string>
     */
    public function baseFilter(ServiceInstance $instance): array
    {
        $instance->loadMissing(['service', 'environment']);

        return ['kind:base_backup', 'environment:'.$instance->environment->public_id, 'service:'.$instance->service->public_id];
    }

    /**
     * The range a point in time can be picked from: from the oldest kept
     * base backup to the newest shipped WAL (null when nothing is restorable).
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function window(WalArchive $archive): ?array
    {
        $oldest = BackupRun::query()
            ->where('service_instance_id', $archive->service_instance_id)
            ->where('subject', BackupSubject::BaseBackup->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->where('finished_at', '>=', now()->subDays($archive->keep_days))
            ->min('finished_at');

        if (! is_string($oldest) && ! $oldest instanceof \DateTimeInterface) {
            return null;
        }

        $start = CarbonImmutable::parse($oldest);
        $end = $archive->last_archived_at?->toImmutable();

        return [$start, $end instanceof CarbonImmutable && $end->gt($start) ? $end : $start];
    }

    /**
     * The newest succeeded base backup run finished at or before $at.
     */
    public function baseFor(ServiceInstance $instance, ?CarbonImmutable $at): ?BackupRun
    {
        return BackupRun::query()
            ->where('service_instance_id', $instance->id)
            ->where('subject', BackupSubject::BaseBackup->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->whereNotNull('snapshot_id')
            ->when($at instanceof CarbonImmutable, static fn ($query) => $query->where('finished_at', '<=', $at))
            ->latest('finished_at')
            ->first();
    }

    /**
     * POSTGRES_USER, POSTGRES_PASSWORD and POSTGRES_DB of an instance's
     * active deployment (the replay adopts its password; verifications
     * connect as its role). Secrets: never log the result.
     *
     * @return array{POSTGRES_USER: string, POSTGRES_PASSWORD: string, POSTGRES_DB: string}|null
     */
    public function postgresEnv(ServiceInstance $instance): ?array
    {
        $deployment = $instance->active_deployment_id === null ? null : Deployment::query()->with('environment.project')->find($instance->active_deployment_id);

        if (! $deployment instanceof Deployment || $deployment->env_snapshot === null) {
            return null;
        }

        $env = $this->keyring->decryptSnapshot($deployment->environment->project, $deployment->public_id, $deployment->env_snapshot);
        $user = is_string($env['POSTGRES_USER'] ?? null) && $env['POSTGRES_USER'] !== '' ? $env['POSTGRES_USER'] : 'postgres';
        $db = is_string($env['POSTGRES_DB'] ?? null) && $env['POSTGRES_DB'] !== '' ? $env['POSTGRES_DB'] : $user;
        $password = is_string($env['POSTGRES_PASSWORD'] ?? null) ? $env['POSTGRES_PASSWORD'] : '';

        if ($password === '' || preg_match('/^[A-Za-z0-9_]{1,63}$/D', $user.'') !== 1 || preg_match('/^[A-Za-z0-9_]{1,63}$/D', $db) !== 1) {
            return null;
        }

        return ['POSTGRES_USER' => $user, 'POSTGRES_PASSWORD' => $password, 'POSTGRES_DB' => $db];
    }
}

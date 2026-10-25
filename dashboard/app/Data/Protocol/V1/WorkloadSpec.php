<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\Wire;
use Spatie\LaravelData\Data;

/**
 * NodeState `workloads[]`: one container (one replica of one deployment).
 *
 * `sealedEnv` is the base64 crypto_box_seal of the WorkloadEnv document;
 * sealing is randomized, so `envFingerprint` (stable per deployment) stands
 * in for it when hashing content to decide whether a new version is needed.
 */
final class WorkloadSpec extends Data
{
    /**
     * @param  list<string>|null  $command
     * @param  list<string>|null  $args
     * @param  list<string>|null  $capabilities
     * @param  list<array{network: string, aliases: list<string>}>  $networks
     * @param  list<array{volume: string, target: string}>|null  $mounts
     */
    public function __construct(
        public string $id,
        public string $service,
        public string $environment,
        public string $deployment,
        public string $image,
        public string $sealedEnv,
        public string $envFingerprint,
        public float $cpus,
        public int $memoryMb,
        public int $pids,
        public array $networks,
        public string $role,
        public ?array $command = null,
        public ?array $args = null,
        public ?int $port = null,
        public ?HealthSpec $health = null,
        public ?array $capabilities = null,
        public ?array $mounts = null,
        public string $restart = 'unless-stopped',
        public ?string $drainUntil = null,
        /** Mesh-published port on the server's mesh IP (docs/05 §3), null = not published. */
        public ?int $publishOnMesh = null,
        /** Ship the container's logs continuously (runtime log retention); never changes the container. */
        public bool $shipLogs = false,
        /**
         * Point-in-time recovery (docs/14 §10.2): `{directory, destination, tags, interval}`;
         * `destination.credentials.sealed` is randomized, so `walArchiveFingerprint`
         * stands in for it when hashing.
         *
         * @var array<string, mixed>|null
         */
        public ?array $walArchive = null,
        public ?string $walArchiveFingerprint = null,
        /**
         * How the container is stopped (daemon feature `stopSpec`, D42).
         *
         * @var array{signal: string, timeout: string, noInit?: bool}|null
         */
        public ?array $stop = null,
        /**
         * A scheduled cron service (daemon feature `cronSchedule`): launchroomd
         * starts the created container at every due minute (docs/04 §1).
         *
         * @var array{cron: string, timeZone: string}|null
         */
        public ?array $schedule = null,
        /**
         * Config files written read-only into the container before it
         * starts (daemon feature `configFiles`, D65).
         *
         * @var list<array{path: string, content: string}>|null
         */
        public ?array $files = null,
    ) {}

    public static function fromProtocol(object $wire): self
    {
        $resources = Wire::object($wire, 'resources') ?? new \stdClass;
        $health = Wire::object($wire, 'health');
        $mounts = is_array($wire->mounts ?? null) ? Wire::objects($wire, 'mounts') : null;

        return new self(
            id: Wire::string($wire, 'id'),
            service: Wire::string($wire, 'service'),
            environment: Wire::string($wire, 'environment'),
            deployment: Wire::string($wire, 'deployment'),
            image: Wire::string($wire, 'image'),
            sealedEnv: Wire::string(Wire::object($wire, 'env') ?? new \stdClass, 'sealed'),
            envFingerprint: '',
            cpus: Wire::float($resources, 'cpus'),
            memoryMb: Wire::int($resources, 'memoryMb'),
            pids: Wire::int($resources, 'pids'),
            networks: array_map(static fn (object $network): array => ['network' => Wire::string($network, 'network'), 'aliases' => Wire::strings($network, 'aliases') ?? []], Wire::objects($wire, 'networks')),
            role: Wire::string($wire, 'role'),
            command: Wire::strings($wire, 'command'),
            args: Wire::strings($wire, 'args'),
            port: Wire::nullableInt($wire, 'port'),
            health: $health === null ? null : HealthSpec::fromProtocol($health),
            capabilities: Wire::strings($wire, 'capabilities'),
            mounts: $mounts === null ? null : array_map(static fn (object $mount): array => ['volume' => Wire::string($mount, 'volume'), 'target' => Wire::string($mount, 'target')], $mounts),
            restart: Wire::nullableString($wire, 'restart') ?? 'unless-stopped',
            drainUntil: Wire::nullableString($wire, 'drainUntil'),
            publishOnMesh: ($mesh = Wire::object($wire, 'publishOnMesh')) === null ? null : Wire::int($mesh, 'port'),
            shipLogs: ($wire->shipLogs ?? false) === true,
            walArchive: is_object($wire->walArchive ?? null) ? self::objectToArray($wire->walArchive) : null,
            stop: ($stop = Wire::object($wire, 'stop')) === null ? null : [
                'signal' => Wire::string($stop, 'signal'),
                'timeout' => Wire::string($stop, 'timeout'),
                ...(($stop->noInit ?? false) === true ? ['noInit' => true] : []),
            ],
            schedule: ($schedule = Wire::object($wire, 'schedule')) === null ? null : [
                'cron' => Wire::string($schedule, 'cron'),
                'timeZone' => Wire::string($schedule, 'timeZone'),
            ],
            files: is_array($wire->files ?? null)
                ? array_map(static fn (object $file): array => ['path' => Wire::string($file, 'path'), 'content' => Wire::string($file, 'content')], Wire::objects($wire, 'files'))
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function objectToArray(object $value): array
    {
        /** @var array<string, mixed> $array */
        $array = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        return $array;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return $this->wire(['sealed' => $this->sealedEnv], $this->walArchive);
    }

    /**
     * The wire shape with the sealed env replaced by its stable fingerprint.
     *
     * @return array<string, mixed>
     */
    public function toFingerprint(): array
    {
        $wal = $this->walArchive;

        if (is_array($wal) && is_array($wal['destination'] ?? null)) {
            $wal['destination']['credentials'] = ['fingerprint' => $this->walArchiveFingerprint ?? ''];
        }

        return $this->wire(['fingerprint' => $this->envFingerprint], $wal);
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<string, mixed>|null  $walArchive
     * @return array<string, mixed>
     */
    private function wire(array $env, ?array $walArchive): array
    {
        $wire = [
            'id' => $this->id,
            'service' => $this->service,
            'environment' => $this->environment,
            'deployment' => $this->deployment,
            'image' => $this->image,
        ];

        if ($this->command !== null) {
            $wire['command'] = $this->command;
        }

        if ($this->args !== null) {
            $wire['args'] = $this->args;
        }

        $wire['env'] = $env;

        if ($this->port !== null) {
            $wire['port'] = $this->port;
        }

        if ($this->health instanceof HealthSpec) {
            $wire['health'] = $this->health->toProtocol();
        }

        // JSON_PRESERVE_ZERO_FRACTION isn't used for the wire; 1.0 encodes as 1, which the schema accepts.
        $wire['resources'] = ['cpus' => $this->cpus, 'memoryMb' => $this->memoryMb, 'pids' => $this->pids];

        if ($this->capabilities !== null) {
            $wire['capabilities'] = $this->capabilities;
        }

        $wire['networks'] = $this->networks;

        if ($this->mounts !== null) {
            $wire['mounts'] = $this->mounts;
        }

        if ($this->publishOnMesh !== null) {
            $wire['publishOnMesh'] = ['port' => $this->publishOnMesh];
        }

        $wire['restart'] = $this->restart;
        $wire['role'] = $this->role;

        if ($this->drainUntil !== null) {
            $wire['drainUntil'] = $this->drainUntil;
        }

        if ($this->shipLogs) {
            $wire['shipLogs'] = true;
        }

        if ($walArchive !== null) {
            $wire['walArchive'] = $walArchive;
        }

        if ($this->stop !== null) {
            $wire['stop'] = $this->stop;
        }

        if ($this->files !== null) {
            $wire['files'] = $this->files;
        }

        if ($this->schedule !== null) {
            $wire['schedule'] = $this->schedule;
        }

        return $wire;
    }
}

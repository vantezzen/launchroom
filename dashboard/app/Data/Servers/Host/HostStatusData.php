<?php

declare(strict_types=1);

namespace App\Data\Servers\Host;

use App\Data\Protocol\V1\HostStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * `servers.host_status`: the latest Report.host, refreshed with every report.
 * Only known keys with their types are kept, so nothing a daemon adds ends up
 * in the database or the UI (docs/09 R12: whitelist, never pass through).
 */
final class HostStatusData extends Data
{
    public function __construct(
        public int $cpus,
        public int $memoryMb,
        public int $diskTotalMb,
        public int $diskFreeMb,
        public string $daemonVersion,
        public ?string $dockerVersion = null,
        public ?HostHealthData $health = null,
        public ?RegistryReachData $registry = null,
        public ?DockerReachData $docker = null,
        public ?ReconcileStatusData $reconcile = null,
        public ?DaemonRunData $daemon = null,
        public ?DaemonRefusalData $lastRefusal = null,
    ) {}

    public static function fromProtocol(HostStatus $host): self
    {
        $registry = $host->registry;

        return new self(
            cpus: $host->cpus,
            memoryMb: $host->memoryMb,
            diskTotalMb: $host->diskTotalMb,
            diskFreeMb: $host->diskFreeMb,
            daemonVersion: $host->daemonVersion,
            dockerVersion: $host->dockerVersion instanceof Optional ? null : $host->dockerVersion,
            health: is_array($host->health) ? HostHealthData::fromProtocol($host->health) : null,
            registry: is_array($registry) ? new RegistryReachData(
                reachable: ($registry['reachable'] ?? false) === true,
                checkedAt: is_string($registry['checkedAt'] ?? null) ? $registry['checkedAt'] : null,
                latencyMs: is_int($registry['latencyMs'] ?? null) ? $registry['latencyMs'] : null,
                error: is_string($registry['error'] ?? null) ? mb_substr($registry['error'], 0, 512) : null,
            ) : null,
            docker: self::docker($host->docker),
            reconcile: self::reconcile($host->reconcile),
            daemon: is_array($host->daemon) && is_string($host->daemon['startedAt'] ?? null) ? new DaemonRunData(
                startedAt: $host->daemon['startedAt'],
                hostBootedAt: is_string($host->daemon['hostBootedAt'] ?? null) ? $host->daemon['hostBootedAt'] : null,
            ) : null,
            lastRefusal: self::refusal($host->lastRefusal),
        );
    }

    /**
     * @param  array<array-key, mixed>|null|Optional  $docker
     */
    private static function docker(array|null|Optional $docker): ?DockerReachData
    {
        if (! is_array($docker) || ! is_bool($docker['reachable'] ?? null) || ! is_string($docker['checkedAt'] ?? null)) {
            return null;
        }

        return new DockerReachData($docker['reachable'], $docker['checkedAt'], is_string($docker['error'] ?? null) ? mb_substr($docker['error'], 0, 512) : null);
    }

    /**
     * @param  array<array-key, mixed>|null|Optional  $reconcile
     */
    private static function reconcile(array|null|Optional $reconcile): ?ReconcileStatusData
    {
        if (! is_array($reconcile) || ! is_string($reconcile['lastPassAt'] ?? null)) {
            return null;
        }

        $errors = [];

        foreach (is_array($reconcile['errors'] ?? null) ? $reconcile['errors'] : [] as $error) {
            if (is_array($error) && is_string($error['code'] ?? null)) {
                $errors[] = new ReconcileErrorData(
                    $error['code'],
                    is_string($error['target'] ?? null) ? $error['target'] : null,
                    is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 512) : null,
                );
            }
        }

        return new ReconcileStatusData(
            lastPassAt: $reconcile['lastPassAt'],
            lastCompleteAt: is_string($reconcile['lastCompleteAt'] ?? null) ? $reconcile['lastCompleteAt'] : null,
            errors: array_slice($errors, 0, 16),
        );
    }

    /**
     * @param  array<array-key, mixed>|null|Optional  $refusal
     */
    private static function refusal(array|null|Optional $refusal): ?DaemonRefusalData
    {
        if (! is_array($refusal) || ! is_string($refusal['at'] ?? null) || ! is_int($refusal['status'] ?? null)) {
            return null;
        }

        return new DaemonRefusalData(
            at: $refusal['at'],
            status: $refusal['status'],
            clockSkewMs: is_int($refusal['clockSkewMs'] ?? null) ? $refusal['clockSkewMs'] : null,
            message: is_string($refusal['message'] ?? null) ? mb_substr($refusal['message'], 0, 256) : null,
        );
    }

    public function reportedMemoryMb(): ?int
    {
        return $this->memoryMb > 0 ? $this->memoryMb : null;
    }

    public function reportedCpus(): ?int
    {
        return $this->cpus > 0 ? $this->cpus : null;
    }

    /**
     * Memory reported available (MemAvailable: the page cache counts as
     * free) and when it was measured; null without a fresh measurement
     * (D72). launchroomd measures every 5 minutes.
     *
     * @param  CarbonInterface|null  $reportedAt  when the report arrived, for snapshots without collectedAt
     * @return array{int, CarbonImmutable}|null
     */
    public function availableMemory(?CarbonInterface $reportedAt): ?array
    {
        $available = $this->health?->memory?->availableMb;

        if ($available === null || $available < 0) {
            return null;
        }

        $collectedAt = $this->health->collectedAt;
        $at = $collectedAt !== null ? CarbonImmutable::parse($collectedAt) : $reportedAt?->toImmutable();

        if (! $at instanceof CarbonImmutable || $at->diffInSeconds(now(), absolute: true) > config()->integer('launchroom.scheduling.memory_report_max_age_seconds')) {
            return null;
        }

        return [$available, $at];
    }

    /**
     * Free space where Docker keeps volumes (the Docker disk), else the data disk.
     */
    public function volumeDiskFreeMb(): int
    {
        return $this->health->dockerDisk->freeMb ?? $this->health->dataDisk->freeMb ?? $this->diskFreeMb;
    }

    public function volumeDiskTotalMb(): ?int
    {
        foreach ([$this->health?->dockerDisk?->totalMb, $this->diskTotalMb] as $total) {
            if ($total !== null && $total > 0) {
                return $total;
            }
        }

        return null;
    }
}

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

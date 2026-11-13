<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * A server's cores and memory for scheduling: the latest report
 * (`servers.host_status`, refreshed with every report) first, the facts from
 * enrollment otherwise; null when unknown (no capacity check).
 */
final class HostCapacity
{
    public static function memoryMb(Server $server): ?int
    {
        $reported = $server->host_status['memoryMb'] ?? null;

        return is_int($reported) && $reported > 0 ? $reported : $server->facts->memoryMb;
    }

    /**
     * Memory the server reports available (MemAvailable: the page cache
     * counts as free) and when it was measured; null without a fresh
     * measurement (D72). launchroomd measures every 5 minutes.
     *
     * @return array{int, CarbonImmutable}|null
     */
    public static function availableMemory(Server $server): ?array
    {
        $health = $server->host_status['health'] ?? null;
        $available = is_array($health) && is_array($health['memory'] ?? null) ? ($health['memory']['availableMb'] ?? null) : null;

        if (! is_int($available) || $available < 0) {
            return null;
        }

        try {
            $at = is_string($health['collectedAt'] ?? null) ? CarbonImmutable::parse($health['collectedAt']) : $server->host_status_at?->toImmutable();
        } catch (InvalidFormatException) {
            return null;
        }

        if ($at === null || $at->diffInSeconds(now(), absolute: true) > config()->integer('launchroom.scheduling.memory_report_max_age_seconds')) {
            return null;
        }

        return [$available, $at];
    }

    public static function cpus(Server $server): ?int
    {
        $reported = $server->host_status['cpus'] ?? null;

        return is_int($reported) && $reported > 0 ? $reported : $server->facts->cpus;
    }
}

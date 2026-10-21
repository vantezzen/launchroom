<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;

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

    public static function cpus(Server $server): ?int
    {
        $reported = $server->host_status['cpus'] ?? null;

        return is_int($reported) && $reported > 0 ? $reported : $server->facts->cpus;
    }
}

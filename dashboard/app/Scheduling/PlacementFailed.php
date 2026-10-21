<?php

declare(strict_types=1);

namespace App\Scheduling;

use DomainException;

/**
 * No valid placement exists; the message is user-facing (`placement_error`).
 */
final class PlacementFailed extends DomainException
{
    /**
     * @param  array<int, string>  $rejected  server id => why it can't run the service
     * @param  array<int, string>  $names  server id => display name
     */
    public static function noEligibleServers(array $rejected, array $names = []): self
    {
        if ($rejected === []) {
            return new self('No server can run this service: there is no approved server with the runtime role. Add a server or give one the runtime role.');
        }

        $parts = [];

        foreach ($rejected as $id => $reason) {
            $parts[] = ($names[$id] ?? "server {$id}").': '.$reason;
        }

        return new self('No server satisfies this service\'s placement constraints ('.implode('; ', array_slice($parts, 0, 6)).(count($parts) > 6 ? '; …' : '').'). Change the placement settings in Settings → Scaling, or the server\'s labels and settings.');
    }

    public static function pinnedUnavailable(): self
    {
        return new self('The server holding this service\'s volume is not available. Stateful services are never moved automatically; bring the server back or restore from a backup on another server.');
    }

    public static function insufficientCapacity(int $memoryMb, float $cpus): self
    {
        $cpu = rtrim(rtrim(number_format($cpus, 2, '.', ''), '0'), '.');

        return new self("No eligible server has room for another replica ({$memoryMb} MB memory, {$cpu} CPU). Lower the limits or replicas, or add a server.");
    }
}

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
        return new self('The server holding this service\'s volume is not available. Services with data are not moved automatically. Bring the server back online, or restore from a backup on another server.');
    }

    /**
     * No eligible server has $needMb free (limits are caps, D72: only real
     * memory blocks a deploy, never CPU).
     */
    public static function insufficientMemory(int $needMb, int $mostFreeMb, int $replicas = 1): self
    {
        $what = $replicas > 1 ? "all {$replicas} replicas of this service" : 'this service';

        return new self("There isn't enough free memory for {$what} right now. A replica needs about ".self::mb($needMb).', and the most any server has left is '.self::mb($mostFreeMb).'. Stop services you don\'t need or add a server, then deploy again.');
    }

    /** "512 MB", "1.5 GB". */
    public static function mb(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1, '.', ''), '0'), '.').' GB' : "{$mb} MB";
    }
}

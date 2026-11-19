<?php

declare(strict_types=1);

namespace App\Queries\Backups;

use App\Data\Servers\Host\DiskBreakdownData;
use App\Data\Servers\Host\HostHealthData;
use App\Models\Server;
use App\Models\Volume;

/**
 * The size of volumes as their servers last measured them (the disk
 * breakdown in Report.host.health.disk, every 30 minutes), in bytes. Used
 * where a volume's size is shown: `volumes.size_bytes` is only a fallback.
 */
final class LiveVolumeSizes
{
    private const int MIB = 1024 * 1024;

    /**
     * @param  iterable<Volume>  $volumes
     * @return array<string, int> volume public id => bytes
     */
    public static function of(iterable $volumes): array
    {
        $servers = [];

        foreach ($volumes as $volume) {
            if ($volume->server_id !== null) {
                $servers[$volume->server_id] = true;
            }
        }

        if ($servers === []) {
            return [];
        }

        $out = [];

        foreach (Server::query()->whereIn('id', array_keys($servers))->get(['id', 'host_status']) as $server) {
            $health = $server->host_status->health ?? new HostHealthData;
            $breakdown = $health->disk;

            if (! $breakdown instanceof DiskBreakdownData) {
                continue;
            }

            foreach ($breakdown->volumes as $usage) {
                if (! $usage->retired) {
                    $out[$usage->id] = $usage->sizeMb * self::MIB;
                }
            }
        }

        return $out;
    }
}

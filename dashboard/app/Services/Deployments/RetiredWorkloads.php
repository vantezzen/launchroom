<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Models\Placement;
use Illuminate\Support\Facades\Cache;

/**
 * Which deployment a workload belonged to after its placement was deleted
 * (a failed, cancelled or stopped rollout, D68). launchroomd reports the
 * container's last events and ships its last output lines a moment later;
 * without its placement the dashboard couldn't tie them to the deployment
 * (and the server's right to write them). Kept for two hours.
 */
final class RetiredWorkloads
{
    private const int TTL_SECONDS = 7200;

    /**
     * @param  iterable<Placement>  $placements
     */
    public static function remember(iterable $placements): void
    {
        foreach ($placements as $placement) {
            Cache::put(self::key($placement->server_id, $placement->workload_id), $placement->deployment_id, self::TTL_SECONDS);
        }
    }

    public static function deploymentOf(int $serverId, string $workloadId): ?int
    {
        $id = Cache::get(self::key($serverId, $workloadId));

        return is_int($id) ? $id : null;
    }

    private static function key(int $serverId, string $workloadId): string
    {
        return "retired-workload:{$serverId}:{$workloadId}";
    }
}

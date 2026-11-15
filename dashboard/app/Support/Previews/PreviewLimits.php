<?php

declare(strict_types=1);

namespace App\Support\Previews;

use App\Data\Services\ScalingSettings;

/**
 * Resource caps of preview environments (docs/06 §5): one replica per
 * service and at most `previews.max_cpus` CPU. Memory and placement stay as
 * the base's (an app needing 1 GB still gets it); only servers allowing
 * previews host them (scheduler).
 */
final class PreviewLimits
{
    public const int REPLICAS = 1;

    public static function cpus(): float
    {
        return config()->float('launchroom.previews.max_cpus');
    }

    public static function cap(ScalingSettings $scaling): ScalingSettings
    {
        return new ScalingSettings(
            min(self::REPLICAS, $scaling->replicas),
            min($scaling->cpus, self::cpus()),
            $scaling->memoryMb,
            $scaling->placementLabels,
            $scaling->strategy,
            $scaling->allowServers,
            $scaling->denyServers,
        );
    }
}

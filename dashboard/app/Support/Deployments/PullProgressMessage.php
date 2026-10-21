<?php

declare(strict_types=1);

namespace App\Support\Deployments;

use App\Enums\WorkloadPhase;
use App\Models\Placement;

/**
 * The health step's line while new workloads pull their image (docs/04 §11
 * "Pulls"): "Downloading image (412 MB of 690 MB)". With several servers
 * pulling, the one furthest behind speaks for all of them.
 */
final class PullProgressMessage
{
    /**
     * @param  iterable<Placement>  $placements
     */
    public static function for(iterable $placements): ?string
    {
        $slowest = null;
        $slowestShare = 2.0;
        $count = 0;

        foreach ($placements as $placement) {
            $pull = $placement->pull;

            if ($placement->phase !== WorkloadPhase::Pulling || ! is_array($pull)) {
                continue;
            }

            $count++;
            $share = match ($pull['stage']) {
                'mirroring' => -1.0,
                'extracting' => 1.0,
                default => $pull['total'] > 0 ? min(1.0, $pull['downloaded'] / $pull['total']) : 0.0,
            };

            if ($share < $slowestShare) {
                [$slowest, $slowestShare] = [$pull, $share];
            }
        }

        if ($slowest === null) {
            return null;
        }

        $line = match ($slowest['stage']) {
            'mirroring' => 'Fetching image into the local registry (first deploy of this image)',
            'extracting' => 'Unpacking image'.($slowest['total'] > 0 ? ' ('.self::bytes($slowest['total']).')' : ''),
            default => $slowest['total'] > 0
                ? 'Downloading image ('.self::bytes(min($slowest['downloaded'], $slowest['total'])).' of '.self::bytes($slowest['total']).')'
                : 'Downloading image',
        };

        return $count > 1 ? "{$line} on {$count} servers" : $line;
    }

    /** Decimal units, like registries and Docker show sizes. */
    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1_000_000_000) {
            return number_format($bytes / 1_000_000_000, 1).' GB';
        }

        if ($bytes >= 1_000_000) {
            return number_format(intdiv($bytes, 1_000_000)).' MB';
        }

        return $bytes > 0 ? '< 1 MB' : '0 MB';
    }
}

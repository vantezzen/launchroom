<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Measured quality of the private-network link from a server to one peer
 * (docs/05 §2.1), for the server page: latency now (null when the latest
 * probe failed or is older than 10 minutes), its hourly average over the
 * last 24 hours (oldest first; null where nothing was measured), how many
 * probes failed in that time, and the rates of the last transfers in both
 * directions (`downloadMbps` peer → server, `uploadMbps` server → peer).
 */
final class LinkQualityData extends Data
{
    /**
     * @param  list<float|null>  $latencyHistory  24 hourly averages in ms
     */
    public function __construct(
        public ?float $latencyMs,
        public ?CarbonInterface $measuredAt,
        public ?string $error,
        public array $latencyHistory,
        public int $probes,
        public int $failedProbes,
        public ?float $downloadMbps,
        public ?float $uploadMbps,
        public ?CarbonInterface $throughputAt,
    ) {}
}

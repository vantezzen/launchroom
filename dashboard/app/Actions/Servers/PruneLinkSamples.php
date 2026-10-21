<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes hourly link rollups older than 30 days and latest measurements
 * nobody refreshed for 30 days (a peer that left the mesh, docs/05 §2.1).
 */
final readonly class PruneLinkSamples
{
    public const int RETENTION_DAYS = 30;

    /**
     * @return array{samples: int, links: int}
     */
    public function handle(): array
    {
        $cutoff = CarbonImmutable::now()->subDays(self::RETENTION_DAYS);

        return [
            'samples' => DB::table('server_link_samples')->where('at', '<', $cutoff)->delete(),
            'links' => DB::table('server_links')->where('updated_at', '<', $cutoff)->delete(),
        ];
    }
}

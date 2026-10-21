<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\RetiredVolumeData;
use App\Enums\VolumeRetirementStatus;
use App\Models\Server;
use App\Models\VolumeRetirement;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Retired volumes on a server's page (docs/05 §1.1): everything not yet
 * deleted, plus deletions of the last 7 days.
 */
final class ServerRetiredVolumes
{
    /**
     * @return list<RetiredVolumeData>
     */
    public function for(Server $server): array
    {
        return array_values($server->volumeRetirements()
            ->where(static fn (Builder $query) => $query->where('status', '!=', VolumeRetirementStatus::Deleted->value)->orWhere('deleted_at', '>', now()->subDays(7)))
            ->orderBy('retain_until')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(static fn (VolumeRetirement $retirement): RetiredVolumeData => RetiredVolumeData::fromModel($retirement))
            ->all());
    }
}

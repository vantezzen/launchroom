<?php

declare(strict_types=1);

namespace App\Data\Backups;

use App\Enums\Backups\SnapshotSource;
use App\Services\Backups\SnapshotCache;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A restorable snapshot: from a recorded run or from the repository listing.
 *
 * @see SnapshotCache
 */
final class SnapshotData extends Data
{
    public function __construct(
        public string $id,
        public string $shortId,
        public CarbonInterface $time,
        public ?int $sizeBytes,
        public ?string $reason,
        public SnapshotSource $source,
        /** Volume snapshots: the volume (vol_…) and its mount path. */
        public ?string $volumeId = null,
        public ?string $mountPath = null,
    ) {}
}

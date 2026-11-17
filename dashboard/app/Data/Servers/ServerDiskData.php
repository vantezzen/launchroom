<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Data\Servers\Host\DiskCleanupData;
use Spatie\LaravelData\Data;

/**
 * The server page's Disk section (G18): the disk apps and images live on,
 * what takes it (volumes by size, images, build cache, the WAL backlog, the
 * registry), the file-entry (inode) headroom, the last cleanup and "Free
 * space now". Sizes come from the server's reports; volumes are measured
 * every 30 minutes (`measuredAt`).
 */
final class ServerDiskData extends Data
{
    /**
     * @param  list<DiskVolumeData>  $volumes
     */
    public function __construct(
        public ?int $totalMb,
        public ?int $freeMb,
        public ?float $inodesFreePercent,
        public ?int $imagesMb,
        public ?int $imagesReclaimableMb,
        public ?int $buildCacheMb,
        public ?int $walBacklogMb,
        public ?int $registryMb,
        public array $volumes,
        public ?string $measuredAt,
        public ?DiskCleanupData $lastCleanup,
        public ?CleanupTaskData $cleanupTask,
        /** May start "Free space now" (ServerPolicy::update and an online server that supports it). */
        public bool $canFreeSpace,
        /** Why "Free space now" isn't offered, for people who may change the server. */
        public ?string $freeSpaceUnavailable = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * One volume in a server's Disk section: whose it is ("postgres", "shop /
 * production") and its size. `retired`: no environment uses it anymore
 * (it waits for deletion under Settings → Storage).
 */
final class DiskVolumeData extends Data
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $detail,
        public ?string $mountPath,
        public int $sizeMb,
        public bool $retired,
    ) {}
}

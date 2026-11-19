<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * "Back up this volume" on a Backups page volume row.
 */
final class BackupVolumeSwitchData extends Data
{
    public function __construct(
        public bool $enabled,
        /** PUT {enabled: boolean}; null without permission. */
        public ?string $url,
        /** The mount path looks like a cache (/tmp, cache, node_modules). */
        public bool $looksLikeCache,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Update" on Settings → Updates: the release to install, whether every
 * server follows once the new dashboard is up, and, when no control-plane
 * backup destination exists, the acknowledgement to update without an
 * off-site backup.
 */
final class StartControlPlaneUpdateInput extends Data
{
    public function __construct(
        #[Max(64), Regex('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D')]
        public string $version,
        public bool $skipBackup = false,
        public bool $updateServers = false,
    ) {}
}

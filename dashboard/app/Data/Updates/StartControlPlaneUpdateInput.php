<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * "Update" on Settings → Updates: the release to install, whether every
 * server follows once the new dashboard is up, and which backup comes
 * first (docs/11 §4.1):
 *
 * - `backupRunId`: a control-plane backup to use instead of a new one, the
 *   latest succeeded one or the one running now (the update waits for it);
 * - `skipBackup`: no new backup. Without a control-plane backup destination
 *   it is the required acknowledgement to update without an off-site backup;
 * - neither: a new pre-upgrade backup.
 *
 * `retryOf`: "Try again" on an update that didn't go through; its pinned
 * release is used again (docs/11 §4.3).
 */
final class StartControlPlaneUpdateInput extends Data
{
    public function __construct(
        #[Max(64), Regex('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D')]
        public string $version,
        public bool $skipBackup = false,
        public bool $updateServers = false,
        #[Max(64), Regex('/^bkr_[0-9A-Za-z]+$/D')]
        public ?string $backupRunId = null,
        #[Max(64), Regex('/^cpu_[0-9A-Za-z]+$/D')]
        public ?string $retryOf = null,
    ) {}
}

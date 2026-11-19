<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * "Where backups go" on the Backups page.
 */
final class BackupStorageData extends Data
{
    /**
     * @param  list<BackupDestinationData>  $destinations
     * @param  list<RemovedDestinationData>  $removed
     */
    public function __construct(
        public array $destinations,
        public array $removed,
        public bool $canManage,
        /** POST the setup form (BackupDestinationInput + protectLaunchroom). */
        public BackupActionData $add,
        /** Offer "Also protect launchroom itself" in setup: an instance owner, and launchroom has no storage yet. */
        public bool $canProtectLaunchroom,
    ) {}
}

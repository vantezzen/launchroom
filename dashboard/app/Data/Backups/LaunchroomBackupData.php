<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * launchroom itself on the Backups page: its row, its storage and setup,
 * its recent runs and the recovery kit. Instance owners act on it; team
 * owners and admins who aren't instance owners see the row read-only.
 */
final class LaunchroomBackupData extends Data
{
    /**
     * @param  list<BackupRunData>  $runs  newest first; empty for non-owners
     */
    public function __construct(
        public bool $isOwner,
        public BackupItemData $item,
        public ?BackupDestinationData $destination,
        /** The team storage it was copied from (same bucket, folder launchroom/). */
        public ?string $copiedFrom,
        /** POST {from: <team storage id>}: copy a team storage for launchroom itself. */
        public BackupActionData $setup,
        public array $runs,
        public ?RecoveryKitData $recoveryKit,
    ) {}
}

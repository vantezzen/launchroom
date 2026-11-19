<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * Props of the Backups page (docs/14 §3, docs/design/flows.md §14).
 */
final class BackupsPageData extends Data
{
    /**
     * @param  list<BackupGroupData>  $groups
     * @param  list<NotBackedUpItemData>  $notBackedUp
     */
    public function __construct(
        public string $teamId,
        public BackupsSummaryData $summary,
        public array $groups,
        public array $notBackedUp,
        public BackupStorageData $storage,
        public ?LaunchroomBackupData $launchroom,
        public BackupEverythingData $everything,
        public BackupLiveData $live,
    ) {}
}

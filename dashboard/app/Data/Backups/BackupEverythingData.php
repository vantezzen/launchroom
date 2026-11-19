<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * "Back up everything now": the action and the newest batch's progress.
 */
final class BackupEverythingData extends Data
{
    public function __construct(
        public BackupActionData $action,
        public ?BackupBatchProgressData $batch,
    ) {}
}

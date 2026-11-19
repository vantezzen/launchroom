<?php

declare(strict_types=1);

namespace App\Data\Backups;

/**
 * What "Back up everything now" did (App\Actions\Backups\StartAllBackups):
 * the batch id its runs carry, how many runs it started, how many items were
 * already backing up, and the items that couldn't start with why.
 */
final readonly class BackupBatch
{
    /**
     * @param  list<array{name: string, message: string}>  $refused
     */
    public function __construct(
        public string $id,
        public int $started = 0,
        public int $alreadyRunning = 0,
        public array $refused = [],
    ) {}
}

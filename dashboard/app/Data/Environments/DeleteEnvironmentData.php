<?php

declare(strict_types=1);

namespace App\Data\Environments;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Delete an environment: the user types its name. Databases and volumes
 * with backups get a final backup first (docs/14 §9.13); `skipFinalBackup`
 * (owners/admins, authorized, audited) deletes without one.
 */
final class DeleteEnvironmentData extends Data
{
    public function __construct(
        #[Max(100)]
        public string $confirmName,
        public bool $skipFinalBackup = false,
    ) {}
}

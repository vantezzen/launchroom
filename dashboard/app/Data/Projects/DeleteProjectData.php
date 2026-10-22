<?php

declare(strict_types=1);

namespace App\Data\Projects;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Delete a project (docs/08 §2 "danger"): the user types the project's name.
 * Databases and volumes with backups get a final backup first (docs/14
 * §9.13; snapshots stay in the destination); `skipFinalBackup` (owners/
 * admins, authorized, audited) deletes without one.
 */
final class DeleteProjectData extends Data
{
    public function __construct(
        #[Max(100)]
        public string $confirmName,
        public bool $skipFinalBackup = false,
    ) {}
}

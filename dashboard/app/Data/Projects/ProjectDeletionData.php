<?php

declare(strict_types=1);

namespace App\Data\Projects;

use App\Data\Backups\DeletionRequestData;
use Spatie\LaravelData\Data;

/**
 * What deleting a project would remove (Settings → Danger zone).
 */
final class ProjectDeletionData extends Data
{
    public function __construct(
        public int $servicesWithBackups,
        /** A backup of one of its services is running. */
        public bool $inFlight,
        public int $services,
        public int $environments,
        /** A deletion waiting for its final backup (docs/14 §9.13). */
        public ?DeletionRequestData $pending,
        public bool $canSkipFinalBackup,
    ) {}
}

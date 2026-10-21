<?php

declare(strict_types=1);

namespace App\Data\Environments;

use App\Data\Backups\DeletionRequestData;
use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * One environment on `/p/{project}/settings/environments` (docs/08 §2).
 */
final class EnvironmentSettingsData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public EnvironmentKind $kind,
        public EnvironmentTriggers $triggers,
        public bool $autoDeployPaused,
        public ?string $baseName,
        public int $services,
        public ?int $revision,
        public ?string $lastDeployedAt,
        public bool $canUpdate,
        public bool $canDelete,
        /** Databases and services with volumes that get a final backup before deletion (docs/14 §9.13). */
        public int $servicesWithBackups,
        public bool $backupInFlight,
        /** A deletion waiting for (or blocked on) its final backup. */
        public ?DeletionRequestData $pendingDeletion = null,
        /** Owners/admins may delete without a final backup. */
        public bool $canSkipFinalBackup = false,
    ) {}
}

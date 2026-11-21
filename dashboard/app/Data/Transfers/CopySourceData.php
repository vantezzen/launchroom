<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Enums\EnvironmentKind;
use Spatie\LaravelData\Data;

/**
 * Another environment of the project as a copy's source (docs/14 §14.1):
 * the same service there, its backups, and why it can't be used.
 */
final class CopySourceData extends Data
{
    /**
     * @param  list<CopyBackupData>  $backups  newest first
     * @param  list<string>  $mountPaths  the source's volumes
     */
    public function __construct(
        public string $environmentId,
        public string $environmentName,
        public EnvironmentKind $environmentKind,
        /** "PostgreSQL 17"; null for volumes or a missing service. */
        public ?string $versionLabel,
        public array $backups,
        public array $mountPaths,
        /** Why it can't be the source (no such service, versions, permission), or null. */
        public ?string $refusal,
        /** Whether a backup point can be read (the source's backup storage exists). */
        public bool $backupsReadable,
    ) {}
}

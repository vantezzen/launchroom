<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Storage that was removed from launchroom; its backups stay in the bucket
 * and their history stays here, read-only. `passwordKept`: launchroom still
 * has its repository password, so adding it again needs only new access keys.
 */
final class RemovedDestinationData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $endpoint,
        public string $bucket,
        public string $prefix,
        public ?CarbonInterface $removedAt,
        public int $runs,
        public ?CarbonInterface $lastBackupAt,
        public bool $passwordKept = false,
        /** POST: the repository password (audited); null without the permission or the password. */
        public ?string $repositoryPasswordUrl = null,
    ) {}
}

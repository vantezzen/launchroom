<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

use App\Models\Backups\BackupDestination;
use App\Services\Backups\DestinationCredentials;

/**
 * A restic repository plus the credentials to open it.
 */
final readonly class ResticTarget
{
    public function __construct(
        public string $repository,
        public ?string $region,
        public DestinationCredentials $credentials,
    ) {}

    public static function for(BackupDestination $destination, DestinationCredentials $credentials): self
    {
        return new self($destination->repository(), $destination->region, $credentials);
    }
}

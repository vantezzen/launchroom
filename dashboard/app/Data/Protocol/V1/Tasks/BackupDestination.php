<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use Spatie\LaravelData\Data;

/**
 * A restic repository (protocol BackupDestination). `credentials` (a TaskEnv
 * with AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and RESTIC_PASSWORD) is
 * sealed at lease time.
 */
final class BackupDestination extends Data
{
    public function __construct(
        public string $kind,
        public ?string $endpoint = null,
        public ?string $bucket = null,
        public ?string $region = null,
        public ?string $prefix = null,
        public ?Sealed $credentials = null,
    ) {}
}

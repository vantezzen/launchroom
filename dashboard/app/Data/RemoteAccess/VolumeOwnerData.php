<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use Spatie\LaravelData\Data;

/**
 * `remote_accesses.volume_owner`: who SFTP reads and writes as in a volume
 * access (AccessStatus.owner, D67).
 */
final class VolumeOwnerData extends Data
{
    public function __construct(
        public int $uid,
        public int $gid,
    ) {}
}

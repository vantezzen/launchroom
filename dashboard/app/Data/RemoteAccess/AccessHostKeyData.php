<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use Spatie\LaravelData\Data;

/**
 * `remote_accesses.host_key`: the SSH host key the access endpoint presents
 * (AccessStatus.hostKey, docs/08 §15).
 */
final class AccessHostKeyData extends Data
{
    public function __construct(
        public string $type,
        public string $sha256,
        public string $publicKey,
    ) {}
}

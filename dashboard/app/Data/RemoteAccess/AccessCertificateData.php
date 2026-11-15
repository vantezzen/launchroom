<?php

declare(strict_types=1);

namespace App\Data\RemoteAccess;

use Spatie\LaravelData\Data;

/**
 * `remote_accesses.certificate`: the TLS certificate a database access
 * presents (AccessStatus.certificate).
 */
final class AccessCertificateData extends Data
{
    public function __construct(
        public string $sha256,
        public string $pem,
    ) {}
}

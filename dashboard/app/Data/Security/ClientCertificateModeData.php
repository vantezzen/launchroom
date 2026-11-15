<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\ClientCertificateMode;
use Spatie\LaravelData\Data;

/**
 * Settings → Security → Server certificates (D50): the mode, and `force` to
 * require certificates while some servers have none yet.
 */
final class ClientCertificateModeData extends Data
{
    public function __construct(
        public ClientCertificateMode $mode,
        public bool $force = false,
    ) {}
}

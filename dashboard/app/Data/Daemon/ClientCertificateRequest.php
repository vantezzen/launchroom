<?php

declare(strict_types=1);

namespace App\Data\Daemon;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * POST /daemon/v1/client-certificate (protocol/schema/v1/client-certificate-request.json):
 * standard base64 of the DER SubjectPublicKeyInfo of the ECDSA P-256 key
 * launchroomd generated for TLS (docs/03 §4.3).
 */
final class ClientCertificateRequest extends Data
{
    public function __construct(
        #[In(['launchroom.vantezzen.io/v1'])]
        public string $apiVersion,
        #[In(['ClientCertificateRequest'])]
        public string $kind,
        #[Max(512), Regex('/^[A-Za-z0-9+\/]+={0,2}$/D')]
        public string $publicKey,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `ingress.certificates[]` (report.json CertificateStatus, docs/05 §5).
 */
final class CertificateStatus extends Data
{
    public function __construct(
        #[Max(253), Regex('/^[a-z0-9.-]+$/D')]
        public string $host,
        #[In(['issued', 'pending', 'failed'])]
        public string $state,
        public bool|Optional $wildcard = new Optional,
        #[Max(256)]
        public string|Optional $issuer = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $notBefore = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $notAfter = new Optional,
        #[Max(1024)]
        public string|Optional $lastError = new Optional,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $lastErrorAt = new Optional,
    ) {}
}

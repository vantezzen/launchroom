<?php

declare(strict_types=1);

namespace App\Data\Instance;

use App\Enums\CertStatus;
use Spatie\LaravelData\Data;

/**
 * `dashboard_domain_changes.certificate`: the main server's certificate
 * report for a dashboard domain being set up.
 */
final class DashboardCertificateData extends Data
{
    public function __construct(
        public CertStatus $state,
        public ?string $issuer = null,
        public ?string $notAfter = null,
        public ?string $lastError = null,
        public ?string $reportedAt = null,
    ) {}
}

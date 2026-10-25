<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `ingress` (report.json IngressStatus): the edge Caddy's state and
 * the certificates of its routed hosts (docs/05 §5).
 */
final class IngressStatus extends Data
{
    /**
     * @param  list<CertificateStatus>|null|Optional  $certificates
     */
    public function __construct(
        #[In(['pending', 'applied', 'failed'])]
        public string $state,
        #[Min(0)]
        public int $routes,
        #[Regex(Report::TIME_PATTERN)]
        public string|null|Optional $appliedAt = new Optional,
        #[Max(4096)]
        public string|Optional $message = new Optional,
        #[Max(4096)]
        public array|null|Optional $certificates = new Optional,
        public CertStorageStatus|null|Optional $storage = new Optional,
        /** The web firewall (docs/03 §13.4), edges with the `waf` feature. */
        public WafStatus|null|Optional $waf = new Optional,
    ) {}
}

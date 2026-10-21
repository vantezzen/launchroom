<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Domains\CertificateData;
use App\Data\Domains\DnsCheckData;
use App\Enums\DomainKind;
use Spatie\LaravelData\Data;

final class DomainData extends Data
{
    /**
     * @param  list<DnsRecordData>  $dnsRecords  what to point at us (custom domains only)
     */
    public function __construct(
        public string $id,
        public string $hostname,
        public DomainKind $kind,
        public ?string $redirectTo,
        /** Reported by the edges (null: no report yet). */
        public ?CertificateData $certificate,
        public array $dnsRecords,
        /** Not live yet: added or changed since the last deploy. */
        public bool $staged,
        public bool $canDelete,
        /** Custom domains: the latest live DNS check (null before the first one). */
        public ?DnsCheckData $verification = null,
        /** Custom domains: `www.<hostname>` redirects here. */
        public bool $wwwRedirect = false,
        /** Custom subdomains may CNAME to this generated hostname instead of A/AAAA records. */
        public ?string $cnameTarget = null,
        /** The www redirect toggle applies (custom, not www., not itself a redirect). */
        public bool $canRedirectWww = false,
        /** Custom domains: Strict-Transport-Security once the certificate is issued. */
        public bool $hsts = false,
        /** The HSTS toggle applies (custom, not itself a redirect). */
        public bool $canSetHsts = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Enums\DnsProvider;
use Spatie\LaravelData\Data;

/**
 * Servers → Ingress & certificates (instance owners, docs/05 §4–5): the DNS
 * provider for wildcard certificates, shared certificate storage and every
 * edge's state. Never carries the token.
 */
final class IngressSettingsData extends Data
{
    /**
     * @param  list<DnsProviderOptionData>  $providers  the providers the edge image has
     * @param  array<string, string|null>  $storedFields  the current provider's non-secret stored values (secret ones: null)
     * @param  list<EdgeIngressStatusData>  $edges
     */
    public function __construct(
        public ?string $appsDomain,
        public ?DnsProvider $dnsProvider,
        public array $providers,
        public bool $hasToken,
        public array $storedFields,
        public ?DnsProviderCheckData $verification,
        /** The edge Caddy image has the storage and DNS modules (launchroom-caddy). */
        public bool $modulesAvailable,
        /** Generated domains currently get the DNS-01 wildcard certificate. */
        public bool $wildcardActive,
        public bool $storageConfigured,
        public ?string $storageAddress,
        public array $edges,
        public EdgeOverviewData $overview,
    ) {}
}

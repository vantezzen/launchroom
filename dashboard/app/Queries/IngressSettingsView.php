<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Ingress\DnsProviderCheckData;
use App\Data\Ingress\DnsProviderOptionData;
use App\Data\Ingress\EdgeIngressStatusData;
use App\Data\Ingress\IngressSettingsData;
use App\Enums\DnsProvider;
use App\Models\InstanceSettings;
use App\Services\Ingress\DnsCredentials;
use App\Services\Ingress\EdgeIngress;
use App\Services\Ingress\EdgeServers;

/**
 * Ingress & certificates for Settings → Network (instance owners, docs/05
 * §4–5): DNS provider for wildcard certificates, shared certificate storage
 * and each edge's reported state. Never includes the token itself.
 */
final readonly class IngressSettingsView
{
    public function __construct(
        private EdgeIngress $ingress,
        private DnsCredentials $credentials,
        private EdgeServers $edges,
        private EdgeOverview $overview,
    ) {}

    public function get(): IngressSettingsData
    {
        $settings = InstanceSettings::current();
        $storage = $this->ingress->storageConfig();

        return new IngressSettingsData(
            appsDomain: $settings->apps_domain,
            dnsProvider: $settings->dns_provider,
            providers: array_map(static fn (DnsProvider $provider): DnsProviderOptionData => new DnsProviderOptionData(
                value: $provider,
                label: $provider->label(),
                help: $provider->help(),
                fields: $provider->fields(),
            ), $this->ingress->dnsProviders()),
            hasToken: $this->credentials->fingerprint() !== null,
            storedFields: $this->storedFields($settings->dns_provider),
            verification: DnsProviderCheckData::fromStored($settings->dns_verification),
            modulesAvailable: $this->ingress->modulesAvailable(),
            wildcardActive: $this->ingress->wildcardDomain() !== null,
            storageConfigured: $storage !== null,
            storageAddress: $storage['address'] ?? null,
            edges: array_map(EdgeIngressStatusData::fromServer(...), $this->edges->servers()),
            overview: $this->overview->for(null),
        );
    }

    /**
     * The non-secret stored values of the current provider (OVH region,
     * netcup customer number, Route 53 access key ID), so the form can show
     * them; secret fields are only marked as stored.
     *
     * @return array<string, string|null>
     */
    private function storedFields(?DnsProvider $provider): array
    {
        $values = $provider instanceof DnsProvider ? ($this->credentials->for($provider) ?? []) : [];
        $out = [];

        foreach ($provider?->fields() ?? [] as $field) {
            $out[$field->key] = $field->secret || ! isset($values[$field->key]) ? null : $values[$field->key];
        }

        return $out;
    }
}

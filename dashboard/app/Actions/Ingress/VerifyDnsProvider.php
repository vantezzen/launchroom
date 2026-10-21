<?php

declare(strict_types=1);

namespace App\Actions\Ingress;

use App\Data\Ingress\DnsProviderCheckData;
use App\Enums\DnsProvider;
use App\Models\InstanceSettings;
use App\Services\Ingress\DnsCredentials;
use App\Services\Ingress\DnsProviderVerifier;

/**
 * "Verify": checks the stored DNS provider credentials against the
 * provider's API (DnsProviderVerifier, read-only) and stores the
 * secret-free result on the instance settings.
 */
final readonly class VerifyDnsProvider
{
    public function __construct(
        private DnsCredentials $credentials,
        private DnsProviderVerifier $verifier,
    ) {}

    public function handle(): DnsProviderCheckData
    {
        $settings = InstanceSettings::current();
        $provider = $settings->dns_provider;
        $values = $provider instanceof DnsProvider ? $this->credentials->for($provider) : null;

        $result = $provider instanceof DnsProvider && $values !== null
            ? $this->verifier->verify($provider, $values, (string) $settings->apps_domain)
            : new DnsProviderCheckData(false, 'No DNS provider is configured.', null, now()->toIso8601ZuluString());

        $settings->forceFill([
            'dns_verification' => $result->toArray(),
            'dns_verified_at' => $result->ok ? now() : $settings->dns_verified_at,
        ])->save();

        return $result;
    }
}

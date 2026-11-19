<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Data\Protocol\V1\IngressSpec;
use App\Enums\DnsProvider;
use App\Enums\IngressCredentialPurpose;
use App\Enums\NodeStateField;
use App\Enums\ServerRole;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Traces\TraceKey;
use App\Services\NodeStates\FeatureGate;
use App\Services\Traces\TraceKeys;
use App\Support\Crypto\IngressSecretSealer;
use App\Support\Hostname;
use App\Support\Network\IpClassifier;

/**
 * The edge-wide certificate setup of a server's NodeState (`ingress`,
 * docs/05 §4–5): shared certificate storage and the DNS-01 provider for the
 * platform domain, with credentials sealed to that edge only, and only to
 * edges an instance owner admitted (Server::isSharedEdge). Nothing is
 * sent unless the edge Caddy image has the modules (config
 * `launchroom.ingress.caddy_modules`). Every edge gets its own request
 * trace key (docs/23 §4; dropped for servers without `requestTrace`).
 */
final readonly class EdgeIngress
{
    private const string PREFIX_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/D';

    public function __construct(
        private IngressSecrets $secrets,
        private DnsCredentials $dnsCredentials,
        private CertStorage $certStorage,
        private TraceKeys $traceKeys,
    ) {}

    public function modulesAvailable(): bool
    {
        return config()->boolean('launchroom.ingress.caddy_modules');
    }

    /**
     * The DNS providers the edge Caddy image has (`launchroom.ingress.dns_providers`,
     * empty = all of this release's image); none without launchroom's image.
     *
     * @return list<DnsProvider>
     */
    public function dnsProviders(): array
    {
        if (! $this->modulesAvailable()) {
            return [];
        }

        $configured = config()->array('launchroom.ingress.dns_providers');

        if ($configured === []) {
            return DnsProvider::cases();
        }

        return array_values(array_filter(DnsProvider::cases(), static fn (DnsProvider $provider): bool => in_array($provider->value, $configured, true)));
    }

    public function dnsProviderAvailable(DnsProvider $provider): bool
    {
        return in_array($provider, $this->dnsProviders(), true);
    }

    /**
     * The platform domain when generated domains get a DNS-01 wildcard
     * certificate (`*.<project>.<apps domain>`), else null.
     */
    public function wildcardDomain(?Server $server = null): ?string
    {
        $settings = InstanceSettings::current();

        // A launchroomd that predates a provider would refuse the whole NodeState.
        if ($server instanceof Server && $settings->dns_provider?->needsDaemonFeature() === true
            && ! FeatureGate::accepts($server, NodeStateField::IngressDnsProvider)) {
            return null;
        }
        $domain = $settings->apps_domain;

        if (! $settings->dns_provider instanceof DnsProvider || ! $this->dnsProviderAvailable($settings->dns_provider)
            || ! is_string($domain) || ! Hostname::isValid($domain) || Hostname::isLocal($domain)
            || $this->dnsCredentials->fingerprint() === null) {
            return null;
        }

        return $domain;
    }

    /**
     * Shared certificate storage: the configured one, else the automatic one
     * on the control plane's mesh IP (CertStorage); null when there is none
     * or it is invalid.
     *
     * @return array{address: string, username: string, password: string, keyPrefix: string}|null
     */
    public function storageConfig(): ?array
    {
        $config = $this->modulesAvailable() ? $this->certStorage->config() : null;

        if ($config === null || ! IpClassifier::isAddressPort($config['address'])
            || $config['password'] === '' || preg_match(self::PREFIX_PATTERN, $config['keyPrefix']) !== 1) {
            return null;
        }

        return $config;
    }

    /**
     * Storage and DNS only for shared edges (the control plane, or admitted
     * by an instance owner): these credentials are install-wide (docs/09
     * R14, review M2). The trace key belongs to the edge alone.
     */
    public function for(Server $server): ?IngressSpec
    {
        $shared = $server->isSharedEdge();
        $spec = new IngressSpec(
            storage: $shared ? $this->storage($server) : null,
            dns: $shared ? $this->dns($server) : null,
            trace: $this->trace($server),
        );

        return $spec->isEmpty() ? null : $spec;
    }

    /**
     * The edge's current trace key, sealed to it (values `key`, `keyId`).
     * Provisioned outside rendering (App\Actions\Traces\ProvisionTraceKeys).
     *
     * @return array{sealed: string, fingerprint: string}|null
     */
    private function trace(Server $server): ?array
    {
        $key = $server->hasRole(ServerRole::Edge) ? $this->traceKeys->current($server) : null;

        if (! $key instanceof TraceKey) {
            return null;
        }

        return [
            'sealed' => IngressSecretSealer::seal($server->sealing_public_key, $server->public_id, IngressSecretSealer::TRACE, [
                'key' => $this->traceKeys->encoded($key),
                'keyId' => (string) $key->id,
            ]),
            'fingerprint' => TraceKeys::fingerprint($key),
        ];
    }

    /**
     * @return array{address: string, keyPrefix: string, sealed: string, fingerprint: string}|null
     */
    private function storage(Server $server): ?array
    {
        $config = $this->storageConfig();
        // Provisioned outside rendering (App\Actions\Ingress\ProvisionCertStorage).
        $key = $config === null ? null : $this->secrets->get(IngressCredentialPurpose::StorageKey);

        if ($config === null || $key === null) {
            return null;
        }

        $values = array_filter(['username' => $config['username'], 'password' => $config['password'], 'encryptionKey' => $key], static fn (string $value): bool => $value !== '');

        return [
            'address' => $config['address'],
            'keyPrefix' => $config['keyPrefix'],
            'sealed' => IngressSecretSealer::seal($server->sealing_public_key, $server->public_id, IngressSecretSealer::CERT_STORAGE, $values),
            // Secret-free: changes when the password or key changes.
            'fingerprint' => hash('sha256', implode('|', [
                $config['address'], $config['keyPrefix'], $config['username'],
                hash_hmac('sha256', $config['password'], config()->string('app.key')),
                (string) $this->secrets->fingerprint(IngressCredentialPurpose::StorageKey),
            ])),
        ];
    }

    /**
     * @return array{provider: string, domains: list<string>, sealed: string, fingerprint: string}|null
     */
    private function dns(Server $server): ?array
    {
        $domain = $this->wildcardDomain($server);
        $provider = InstanceSettings::current()->dns_provider;
        $values = $domain === null || ! $provider instanceof DnsProvider ? null : $this->dnsCredentials->for($provider);

        if ($domain === null || ! $provider instanceof DnsProvider || $values === null) {
            return null;
        }

        return [
            'provider' => $provider->value,
            'domains' => [$domain],
            // Exactly the provider's values (launchroomd refuses any other name).
            'sealed' => IngressSecretSealer::seal($server->sealing_public_key, $server->public_id, IngressSecretSealer::DNS, $values),
            'fingerprint' => hash('sha256', $provider->value.'|'.$domain.'|'.$this->dnsCredentials->fingerprint()),
        ];
    }

    /**
     * 32 URL-safe characters (192 bits): caddy-storage-redis uses the first
     * 32 bytes of the key for AES-256-GCM.
     */
    public static function generateStorageKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}

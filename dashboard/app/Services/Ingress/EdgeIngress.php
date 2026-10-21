<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Data\Protocol\V1\IngressSpec;
use App\Enums\DaemonFeature;
use App\Enums\DnsProvider;
use App\Enums\IngressCredentialPurpose;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Support\Crypto\IngressSecretSealer;
use App\Support\Hostname;

/**
 * The edge-wide certificate setup of a server's NodeState (`ingress`,
 * docs/05 §4–5): shared certificate storage and the DNS-01 provider for the
 * platform domain, with credentials sealed to that edge only, and only to
 * edges an instance owner admitted (Server::isSharedEdge). Nothing is
 * sent unless the edge Caddy image has the modules (config
 * `launchroom.ingress.caddy_modules`).
 */
final readonly class EdgeIngress
{
    public const string STORAGE_ADDRESS_PATTERN = '/^(?:\d{1,3}(?:\.\d{1,3}){3}|\[[0-9a-f:]+\]):\d{1,5}$/Di';

    private const string PREFIX_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/D';

    public function __construct(
        private IngressSecrets $secrets,
        private DnsCredentials $dnsCredentials,
        private CertStorage $certStorage,
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
            && ! $server->supportsDaemonFeature(DaemonFeature::DnsProviders)) {
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

        if ($config === null || preg_match(self::STORAGE_ADDRESS_PATTERN, $config['address']) !== 1
            || $config['password'] === '' || preg_match(self::PREFIX_PATTERN, $config['keyPrefix']) !== 1) {
            return null;
        }

        return $config;
    }

    /**
     * Only for shared edges (the control plane, or admitted by an instance
     * owner): these credentials are install-wide (docs/09 R14, review M2).
     */
    public function for(Server $server): ?IngressSpec
    {
        if (! $server->isSharedEdge()) {
            return null;
        }

        $spec = new IngressSpec(storage: $this->storage($server), dns: $this->dns($server));

        return $spec->isEmpty() ? null : $spec;
    }

    /**
     * @return array{address: string, keyPrefix: string, sealed: string, fingerprint: string}|null
     */
    private function storage(Server $server): ?array
    {
        $config = $this->storageConfig();

        if ($config === null) {
            return null;
        }

        $key = $this->secrets->ensure(IngressCredentialPurpose::StorageKey, self::generateStorageKey(...));
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

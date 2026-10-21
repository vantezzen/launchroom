<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\Ingress\DnsCredentialFieldData;

/**
 * DNS providers for DNS-01 wildcard certificates of the platform domain
 * (docs/05 §5: Cloudflare first, Hetzner second; D47 the rest).
 * The edge Caddy image (deploy/images/caddy) compiles in exactly these
 * modules; launchroomd validates the same credential names per provider
 * (daemon/internal/state/ingress.go `dnsCredentialKeys`).
 */
enum DnsProvider: string
{
    case Cloudflare = 'cloudflare';
    case Hetzner = 'hetzner';
    case Route53 = 'route53';
    case DigitalOcean = 'digitalocean';
    case Porkbun = 'porkbun';
    case Desec = 'desec';
    case Gandi = 'gandi';
    case Ovh = 'ovh';
    case Netcup = 'netcup';

    /** OVHcloud API regions (go-ovh endpoint names). */
    public const array OVH_ENDPOINTS = ['ovh-eu' => 'Europe', 'ovh-ca' => 'Canada', 'ovh-us' => 'United States'];

    public function label(): string
    {
        return match ($this) {
            self::Cloudflare => 'Cloudflare',
            self::Hetzner => 'Hetzner DNS',
            self::Route53 => 'Amazon Route 53',
            self::DigitalOcean => 'DigitalOcean',
            self::Porkbun => 'Porkbun',
            self::Desec => 'deSEC',
            self::Gandi => 'Gandi',
            self::Ovh => 'OVHcloud',
            self::Netcup => 'netcup',
        };
    }

    /**
     * Cloudflare and Hetzner predate per-provider credentials (D47): every
     * launchroomd understands them; the others need `dnsProviders`.
     */
    public function needsDaemonFeature(): bool
    {
        return ! in_array($this, [self::Cloudflare, self::Hetzner], true);
    }

    /** The Caddy module the edge image must have. */
    public function module(): string
    {
        return 'dns.providers.'.$this->value;
    }

    /** What to create at the provider, in one sentence. */
    public function help(): string
    {
        return match ($this) {
            self::Cloudflare => 'An API token with Zone → Zone → Read and Zone → DNS → Edit, scoped to the zone.',
            self::Hetzner => 'A Hetzner Cloud API token (Read & Write) of the project that holds the DNS zone.',
            self::Route53 => 'An IAM user’s access key with route53:ListHostedZonesByName, ListResourceRecordSets, ChangeResourceRecordSets and GetChange.',
            self::DigitalOcean => 'A personal access token with the domain read and update scopes.',
            self::Porkbun => 'An API key pair, with API access turned on for the domain.',
            self::Desec => 'A token from desec.io/tokens that may manage the domain.',
            self::Gandi => 'A personal access token with “Manage domain name technical configurations” for the domain.',
            self::Ovh => 'An application key and secret plus a consumer key allowed GET/POST/DELETE on /domain/zone/*.',
            self::Netcup => 'Your customer number, an API key and the API password from the netcup customer control panel.',
        };
    }

    /**
     * The credential fields, in form order. `key` is the value name sealed to
     * edges; secret fields are never shown again.
     *
     * @return list<DnsCredentialFieldData>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Cloudflare, self::Hetzner, self::DigitalOcean, self::Desec, self::Gandi => [
                new DnsCredentialFieldData('token', $this === self::Gandi ? 'Personal access token' : 'API token', true),
            ],
            self::Route53 => [
                new DnsCredentialFieldData('accessKeyId', 'Access key ID', false, pattern: '^[A-Z0-9]{16,128}$'),
                new DnsCredentialFieldData('secretAccessKey', 'Secret access key', true),
            ],
            self::Porkbun => [
                new DnsCredentialFieldData('apiKey', 'API key', true),
                new DnsCredentialFieldData('secretKey', 'Secret API key', true),
            ],
            self::Ovh => [
                new DnsCredentialFieldData('endpoint', 'API region', false, options: self::OVH_ENDPOINTS),
                new DnsCredentialFieldData('applicationKey', 'Application key', false),
                new DnsCredentialFieldData('applicationSecret', 'Application secret', true),
                new DnsCredentialFieldData('consumerKey', 'Consumer key', true),
            ],
            self::Netcup => [
                new DnsCredentialFieldData('customerNumber', 'Customer number', false, pattern: '^[0-9]{1,16}$'),
                new DnsCredentialFieldData('apiKey', 'API key', true),
                new DnsCredentialFieldData('apiPassword', 'API password', true),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (DnsCredentialFieldData $field): string => $field->key, $this->fields());
    }
}

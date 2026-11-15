<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Contracts\DnsResolver;
use App\Exceptions\DomainRuleViolation;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;
use App\Support\Network\IpClassifier;
use App\Support\S3\S3Location;

/**
 * Validates S3 destination endpoints before they are saved or used
 * (docs/09 R5, docs/14 §3).
 *
 * restic, not our HTTP client, talks to the endpoint, so the SafeHttpClient
 * IP pin can't apply. What we can and do enforce: the endpoint grammar
 * ({@see S3Location}), https only, and that the host resolves to public addresses only (every
 * record). Only instance owners may allow a LAN/mesh endpoint and plain
 * http per destination (audited, docs/09 R5); even then loopback,
 * link-local/metadata and the control plane's own services are refused.
 * Daemons additionally refuse plain http unless the host is in their local
 * LR_BACKUP_INSECURE_HOSTS.
 *
 * Residual risk (documented in docs/14 §3): DNS could change between this
 * check and restic's own lookup. The check is repeated before every
 * dashboard-side restic run.
 */
final readonly class EndpointGuard
{
    public function __construct(
        private DnsResolver $resolver,
    ) {}

    /**
     * Normalises and checks an endpoint; returns it without a trailing slash.
     *
     * @throws DomainRuleViolation
     */
    public function check(string $endpoint, bool $allowPrivateNetwork): string
    {
        $endpoint = S3Location::endpoint($endpoint);

        if ($allowPrivateNetwork) {
            $this->checkPrivate($endpoint);

            return $endpoint;
        }

        if (! str_starts_with($endpoint, 'https://')) {
            throw DomainRuleViolation::on('endpoint', 'The endpoint must use https. Only an instance owner can allow plain http for a destination on a private network.');
        }

        try {
            new UrlGuard($this->resolver, ['https'])->check($endpoint.'/');
        } catch (UnsafeUrl $e) {
            throw DomainRuleViolation::on('endpoint', $e->getMessage().' Only an instance owner can allow destinations on a private network.');
        }

        return $endpoint;
    }

    /**
     * The instance-owner override (R5) opens LAN, mesh and other private
     * ranges, never the control plane itself: loopback, link-local (cloud
     * metadata), unspecified, multicast and IPv4-embedding IPv6 addresses,
     * and the control plane's own compose service names, are refused even
     * then. Hosts that don't resolve here are left to restic (it fails).
     *
     * @throws DomainRuleViolation
     */
    private function checkPrivate(string $endpoint): void
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        if (in_array($host, UrlGuard::RESERVED_HOSTS, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw DomainRuleViolation::on('endpoint', "{$host} is part of the control plane, not a backup destination.");
        }

        if (preg_match('/^[0-9.]+$|^0x/i', $host) === 1 && ! IpClassifier::isIp($host)) {
            throw DomainRuleViolation::on('endpoint', 'The host name is invalid.');
        }

        foreach (IpClassifier::isIp($host) ? [$host] : $this->resolver->resolve($host) as $ip) {
            $range = IpClassifier::blockedRange($ip);

            if ($range !== null && ! in_array($range, UrlGuard::PRIVATE_RANGES, true)) {
                throw DomainRuleViolation::on('endpoint', 'Backup destinations cannot be on loopback, link-local (cloud metadata) or other reserved addresses, even on a private network.');
            }
        }
    }
}

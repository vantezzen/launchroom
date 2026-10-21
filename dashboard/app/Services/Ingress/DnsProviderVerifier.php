<?php

declare(strict_types=1);

namespace App\Services\Ingress;

use App\Data\Ingress\DnsProviderCheckData;
use App\Enums\DnsProvider;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Support\Hostname;
use App\Support\Ingress\OvhSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use SimpleXMLElement;

/**
 * Checks a DNS provider's credentials before edges use them for DNS-01
 * (docs/05 §5, D47). Read-only: the credentials must be accepted and see
 * the zone that holds the platform domain. Write access (creating the
 * `_acme-challenge` TXT record) shows with the first certificate; failures
 * there are reported per domain.
 *
 * Only the providers' fixed API hosts are called, through SafeHttpClient;
 * the zone names come from the validated platform domain. Secrets are sent
 * as the provider requires (bearer/token header, request signature, JSON
 * body) and never logged or echoed.
 */
final readonly class DnsProviderVerifier
{
    public const string CLOUDFLARE_API = 'https://api.cloudflare.com/client/v4';

    public const string HETZNER_API = 'https://api.hetzner.cloud/v1';

    public const string ROUTE53_API = 'https://route53.amazonaws.com/2013-04-01';

    public const string DIGITALOCEAN_API = 'https://api.digitalocean.com/v2';

    public const string PORKBUN_API = 'https://api.porkbun.com/api/json/v3';

    public const string DESEC_API = 'https://desec.io/api/v1';

    public const string GANDI_API = 'https://api.gandi.net/v5/livedns';

    public const string NETCUP_API = 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON';

    /** go-ovh endpoint name => API base. */
    public const array OVH_APIS = [
        'ovh-eu' => 'https://eu.api.ovh.com/1.0',
        'ovh-ca' => 'https://ca.api.ovh.com/1.0',
        'ovh-us' => 'https://api.us.ovhcloud.com/1.0',
    ];

    public function __construct(
        private SafeHttpClient $http,
    ) {}

    /**
     * @param  array<string, string>  $credentials  the provider's fields (DnsProvider::keys)
     */
    public function verify(DnsProvider $provider, #[SensitiveParameter] array $credentials, string $domain): DnsProviderCheckData
    {
        if (! Hostname::isValid($domain) || substr_count($domain, '.') < 1) {
            return $this->result(false, 'Set a platform domain first (Onboarding → Platform domain).');
        }

        foreach ($provider->keys() as $key) {
            if (($credentials[$key] ?? '') === '') {
                return $this->result(false, "The {$provider->label()} credentials are incomplete. Enter them again.");
            }
        }

        $c = $credentials;

        try {
            return match ($provider) {
                DnsProvider::Cloudflare => $this->cloudflare($c['token'], $domain),
                DnsProvider::Hetzner => $this->hetzner($c['token'], $domain),
                DnsProvider::Route53 => $this->route53($c['accessKeyId'], $c['secretAccessKey'], $domain),
                DnsProvider::DigitalOcean => $this->digitalOcean($c['token'], $domain),
                DnsProvider::Porkbun => $this->porkbun($c['apiKey'], $c['secretKey'], $domain),
                DnsProvider::Desec => $this->desec($c['token'], $domain),
                DnsProvider::Gandi => $this->gandi($c['token'], $domain),
                DnsProvider::Ovh => $this->ovh($c['endpoint'], $c['applicationKey'], $c['applicationSecret'], $c['consumerKey'], $domain),
                DnsProvider::Netcup => $this->netcup($c['customerNumber'], $c['apiKey'], $c['apiPassword'], $domain),
            };
        } catch (ConnectionException) {
            return $this->result(false, "{$provider->label()} could not be reached. Try again in a moment.");
        } catch (UnsafeUrl|ResponseTooLarge) {
            return $this->result(false, "{$provider->label()}'s API answered unexpectedly.");
        }
    }

    private function cloudflare(#[SensitiveParameter] string $token, string $domain): DnsProviderCheckData
    {
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $verify = $this->http->get(self::CLOUDFLARE_API.'/user/tokens/verify', [], $headers);

        if ($verify->status() === 401 || $verify->status() === 403) {
            return $this->result(false, 'Cloudflare rejected the token. Create an API token with Zone → DNS → Edit for this zone.');
        }

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $zones = $this->http->get(self::CLOUDFLARE_API.'/zones', ['name' => $candidate], $headers);

            if (! $zones->successful()) {
                return $this->result(false, 'The token can’t list zones (HTTP '.$zones->status().'). Give it Zone → Zone → Read and Zone → DNS → Edit.');
            }

            $id = $zones->json('result.0.id');

            if (is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1) {
                $records = $this->http->get(self::CLOUDFLARE_API.'/zones/'.$id.'/dns_records', ['per_page' => '1'], $headers);

                if (! $records->successful()) {
                    return $this->result(false, "The token sees {$candidate} but can’t read its DNS records. Give it Zone → DNS → Edit.", $candidate);
                }

                return $this->works($candidate, $domain);
            }
        }

        return $this->result(false, "The token can’t see a zone for {$domain}. Scope it to the zone that contains {$domain}.");
    }

    private function hetzner(#[SensitiveParameter] string $token, string $domain): DnsProviderCheckData
    {
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $zones = $this->http->get(self::HETZNER_API.'/zones', ['name' => $candidate], $headers);

            if ($zones->status() === 401 || $zones->status() === 403) {
                return $this->result(false, 'Hetzner rejected the token. Create a Hetzner Cloud API token (Read & Write) in the project that holds the DNS zone.');
            }

            if (! $zones->successful()) {
                return $this->failed('Hetzner', $zones);
            }

            $name = $zones->json('zones.0.name');

            if (is_string($name) && strtolower($name) === $candidate) {
                return $this->works($candidate, $domain);
            }
        }

        return $this->result(false, "The token’s Hetzner project has no DNS zone for {$domain}.");
    }

    /**
     * ListHostedZonesByName + ListResourceRecordSets, signed with AWS
     * Signature Version 4 (Route 53 is global, signed in us-east-1).
     */
    private function route53(string $accessKeyId, #[SensitiveParameter] string $secretAccessKey, string $domain): DnsProviderCheckData
    {
        foreach ($this->zoneCandidates($domain) as $candidate) {
            $response = $this->route53Get('/2013-04-01/hostedzonesbyname', ['dnsname' => $candidate, 'maxitems' => '1'], $accessKeyId, $secretAccessKey);

            if ($response->status() === 403 || $response->status() === 401) {
                $code = $this->xml($response)?->Error->Code ?? null;

                return $this->result(false, $code !== null && in_array((string) $code, ['AccessDenied', 'AccessDeniedException'], true)
                    ? 'The access key works but may not list hosted zones. Allow route53:ListHostedZonesByName, ListResourceRecordSets, ChangeResourceRecordSets and GetChange.'
                    : 'AWS rejected the access key. Check the access key ID and secret access key.');
            }

            if (! $response->successful()) {
                return $this->failed('Route 53', $response);
            }

            $zone = $this->xml($response)?->HostedZones->HostedZone ?? null;
            $name = $zone === null ? null : strtolower(rtrim((string) $zone->Name, '.'));
            $id = $zone === null ? '' : (string) $zone->Id;

            if ($name === $candidate && preg_match('#^/hostedzone/([A-Z0-9]{1,32})$#D', $id, $m) === 1) {
                $records = $this->route53Get('/2013-04-01/hostedzone/'.$m[1].'/rrset', ['maxitems' => '1'], $accessKeyId, $secretAccessKey);

                if (! $records->successful()) {
                    return $this->result(false, "The access key sees {$candidate} but can’t read its records. Allow route53:ListResourceRecordSets and ChangeResourceRecordSets.", $candidate);
                }

                return $this->works($candidate, $domain);
            }
        }

        return $this->result(false, "The AWS account has no public hosted zone for {$domain}.");
    }

    /**
     * @param  array<string, string>  $query
     */
    private function route53Get(string $path, array $query, string $accessKeyId, #[SensitiveParameter] string $secretAccessKey): Response
    {
        ksort($query);
        $canonicalQuery = implode('&', array_map(static fn (string $k, string $v): string => rawurlencode($k).'='.rawurlencode($v), array_keys($query), $query));
        $host = parse_url(self::ROUTE53_API, PHP_URL_HOST);
        $amzDate = now()->utc()->format('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/us-east-1/route53/aws4_request";
        $canonical = implode("\n", ['GET', $path, $canonicalQuery, "host:{$host}", "x-amz-date:{$amzDate}", '', 'host;x-amz-date', hash('sha256', '')]);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'route53', hash_hmac('sha256', 'us-east-1', hash_hmac('sha256', $date, 'AWS4'.$secretAccessKey, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $key);

        // The query is part of the URL exactly as signed.
        return $this->http->request('GET', 'https://'.$host.$path.'?'.$canonicalQuery, null, [
            'X-Amz-Date' => $amzDate,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, SignedHeaders=host;x-amz-date, Signature={$signature}",
        ]);
    }

    private function digitalOcean(#[SensitiveParameter] string $token, string $domain): DnsProviderCheckData
    {
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $response = $this->http->request('GET', self::DIGITALOCEAN_API.'/domains/'.$candidate, null, $headers);

            if ($response->status() === 401 || $response->status() === 403) {
                return $this->result(false, 'DigitalOcean rejected the token. Create a personal access token with the domain read and update scopes.');
            }

            if ($response->successful() && $this->jsonString($response, 'domain.name') === $candidate) {
                return $this->works($candidate, $domain);
            }

            if ($response->status() !== 404) {
                return $this->failed('DigitalOcean', $response);
            }
        }

        return $this->result(false, "The DigitalOcean account has no domain for {$domain}. Add the zone under Networking → Domains.");
    }

    private function porkbun(#[SensitiveParameter] string $apiKey, #[SensitiveParameter] string $secretKey, string $domain): DnsProviderCheckData
    {
        $auth = ['apikey' => $apiKey, 'secretapikey' => $secretKey];
        $ping = $this->http->postJson(self::PORKBUN_API.'/ping', $auth);

        if ($ping->json('status') !== 'SUCCESS') {
            return $this->result(false, 'Porkbun rejected the API key pair. Create one under Account → API Access.');
        }

        $optIn = null;

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $records = $this->http->postJson(self::PORKBUN_API.'/dns/retrieve/'.$candidate, $auth);

            if ($records->json('status') === 'SUCCESS') {
                return $this->works($candidate, $domain);
            }

            if (str_contains($this->jsonString($records, 'message'), 'opted in')) {
                $optIn = $candidate;
            }
        }

        return $optIn !== null
            ? $this->result(false, "Turn on API access for {$optIn} (Domain Management → Details → API access).", $optIn)
            : $this->result(false, "The Porkbun account has no domain for {$domain}.");
    }

    private function desec(#[SensitiveParameter] string $token, string $domain): DnsProviderCheckData
    {
        $response = $this->http->get(self::DESEC_API.'/domains/', ['owns_qname' => $domain], ['Authorization' => 'Token '.$token, 'Accept' => 'application/json']);

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->result(false, 'deSEC rejected the token. Create one at desec.io/tokens.');
        }

        if (! $response->successful()) {
            return $this->failed('deSEC', $response);
        }

        $name = $response->json('0.name');

        if (is_string($name) && in_array(strtolower($name), $this->zoneCandidates($domain), true)) {
            return $this->works(strtolower($name), $domain);
        }

        return $this->result(false, "The deSEC account has no domain for {$domain}, or the token may not manage it.");
    }

    private function gandi(#[SensitiveParameter] string $token, string $domain): DnsProviderCheckData
    {
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $response = $this->http->request('GET', self::GANDI_API.'/domains/'.$candidate, null, $headers);

            if ($response->status() === 401) {
                return $this->result(false, 'Gandi rejected the token. Create a personal access token under Organizations → Sharing → Personal access tokens.');
            }

            if ($response->status() === 403) {
                return $this->result(false, "The token may not manage {$candidate}. Give it “Manage domain name technical configurations”.", $candidate);
            }

            if ($response->successful() && $this->jsonString($response, 'fqdn') === $candidate) {
                return $this->works($candidate, $domain);
            }

            if ($response->status() !== 404) {
                return $this->failed('Gandi', $response);
            }
        }

        return $this->result(false, "Gandi LiveDNS has no domain for {$domain}.");
    }

    /**
     * GET /domain/zone/{zone}, signed with OVH's scheme:
     * "$1$" + SHA1(secret + consumer key + method + URL + body + timestamp).
     */
    private function ovh(string $endpoint, string $applicationKey, #[SensitiveParameter] string $applicationSecret, #[SensitiveParameter] string $consumerKey, string $domain): DnsProviderCheckData
    {
        $base = self::OVH_APIS[$endpoint] ?? null;

        if ($base === null) {
            return $this->result(false, 'Choose the OVHcloud API region.');
        }

        // The server's clock: OVH refuses signatures off by more than a few seconds.
        $time = $this->http->request('GET', $base.'/auth/time');
        $timestamp = $time->successful() && is_numeric($time->body()) ? (string) (int) $time->body() : (string) now()->getTimestamp();

        foreach ($this->zoneCandidates($domain) as $candidate) {
            $url = $base.'/domain/zone/'.$candidate;
            $signature = OvhSignature::sign($applicationSecret, $consumerKey, 'GET', $url, '', $timestamp);
            $response = $this->http->request('GET', $url, null, [
                'X-Ovh-Application' => $applicationKey,
                'X-Ovh-Consumer' => $consumerKey,
                'X-Ovh-Timestamp' => $timestamp,
                'X-Ovh-Signature' => $signature,
                'Accept' => 'application/json',
            ]);

            if ($response->successful() && $this->jsonString($response, 'name') === $candidate) {
                return $this->works($candidate, $domain);
            }

            if (in_array($response->status(), [400, 401, 403], true)) {
                return $this->result(false, 'OVHcloud rejected the keys or the consumer key may not read the zone. Allow GET/POST/DELETE on /domain/zone/*.');
            }

            if ($response->status() !== 404) {
                return $this->failed('OVHcloud', $response);
            }
        }

        return $this->result(false, "The OVHcloud account has no DNS zone for {$domain}.");
    }

    /**
     * login → infoDnsZone → logout on netcup's CCP API (JSON over POST,
     * read-only).
     */
    private function netcup(string $customerNumber, #[SensitiveParameter] string $apiKey, #[SensitiveParameter] string $apiPassword, string $domain): DnsProviderCheckData
    {
        $login = $this->http->postJson(self::NETCUP_API, ['action' => 'login', 'param' => [
            'customernumber' => $customerNumber, 'apikey' => $apiKey, 'apipassword' => $apiPassword,
        ]]);
        $session = $login->json('responsedata.apisessionid');

        if ($login->json('status') !== 'success' || ! is_string($session) || $session === '') {
            return $this->result(false, 'netcup rejected the customer number, API key or API password.');
        }

        $param = ['customernumber' => $customerNumber, 'apikey' => $apiKey, 'apisessionid' => $session];

        try {
            foreach ($this->zoneCandidates($domain) as $candidate) {
                $zone = $this->http->postJson(self::NETCUP_API, ['action' => 'infoDnsZone', 'param' => [...$param, 'domainname' => $candidate]]);

                if ($zone->json('status') === 'success') {
                    return $this->works($candidate, $domain);
                }
            }
        } finally {
            $this->http->postJson(self::NETCUP_API, ['action' => 'logout', 'param' => $param]);
        }

        return $this->result(false, "The netcup account has no DNS zone for {$domain}.");
    }

    /** A string field of a JSON response, lowercased; '' when absent or not a string. */
    private function jsonString(Response $response, string $key): string
    {
        $value = $response->json($key);

        return is_string($value) ? strtolower($value) : '';
    }

    private function xml(Response $response): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * The domain and its parents down to two labels: the zone may be any of them.
     *
     * @return list<string>
     */
    private function zoneCandidates(string $domain): array
    {
        $labels = explode('.', strtolower($domain));
        $out = [];

        for ($i = 0; count($labels) - $i >= 2 && $i < 8; $i++) {
            $out[] = implode('.', array_slice($labels, $i));
        }

        return $out;
    }

    private function works(string $zone, string $domain): DnsProviderCheckData
    {
        return $this->result(true, "Works for the zone {$zone}. Wildcard certificates for *.<project>.{$domain} use it.", $zone);
    }

    private function failed(string $provider, Response $response): DnsProviderCheckData
    {
        return $this->result(false, "{$provider} answered HTTP {$response->status()}. Try again in a moment.");
    }

    private function result(bool $ok, string $message, ?string $zone = null): DnsProviderCheckData
    {
        return new DnsProviderCheckData($ok, $message, $zone, now()->toIso8601ZuluString());
    }
}

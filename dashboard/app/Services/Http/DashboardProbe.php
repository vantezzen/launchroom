<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Data\Instance\DashboardProbeData;
use App\Support\Hostname;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The HTTPS test request of Settings → Dashboard domain (docs/05 §4.1): does
 * `https://<host>/` reach *this* dashboard through the control-plane Caddy
 * with a certificate the world trusts?
 *
 * It asks `/.well-known/launchroom/dashboard?nonce=…`, which answers an
 * HMAC of the nonce under a key derived from APP_KEY; only this dashboard
 * can produce it, so a valid certificate on someone else's server doesn't
 * pass. Not a user-influenced fetch (docs/09 R5): the connection is pinned
 * (CURLOPT_RESOLVE) to the control-plane server's own public addresses,
 * which DNS was just verified to point at, or to the operator-configured
 * `launchroom.dashboard.probe_address` (host-gateway, for NAT without
 * hairpinning), never to whatever the name resolves to. No redirects, no
 * proxy, short timeouts, normal WebPKI verification (`*.localhost` uses
 * Caddy's internal CA, which this container doesn't trust).
 */
final readonly class DashboardProbe
{
    public const string PATH = '/.well-known/launchroom/dashboard';

    public function __construct(
        private Factory $http,
    ) {}

    /**
     * The proof for a nonce (the endpoint's answer).
     */
    public static function proof(string $nonce): string
    {
        $key = hash_hmac('sha256', 'launchroom:dashboard-probe:v1', config()->string('app.key'));

        return hash_hmac('sha256', $nonce, $key);
    }

    /**
     * @param  list<string>  $addresses  the control-plane server's public addresses that DNS points at
     */
    public function check(string $host, array $addresses): DashboardProbeData
    {
        $targets = [...$addresses, ...$this->probeAddresses()];
        $targets = array_values(array_unique(array_filter($targets, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false)));

        if ($targets === []) {
            return DashboardProbeData::failed('There is no address to test: the main server has no public address yet.');
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $expected = self::proof($nonce);
        $best = [DashboardProbeData::failed('The test request failed.'), -1];

        foreach ($targets as $ip) {
            [$result, $rank] = $this->attempt($host, $ip, $nonce, $expected);

            if ($result->ok) {
                return $result;
            }

            // The most telling failure wins: an answer (wrong status or
            // proof) over a TLS error over not connecting at all (an address
            // that can't be reached from inside, NAT without hairpinning).
            if ($rank > $best[1]) {
                $best = [$result, $rank];
            }
        }

        return $best[0];
    }

    /**
     * @return array{DashboardProbeData, int} the result and how telling a failure is
     */
    private function attempt(string $host, string $ip, string $nonce, string $expected): array
    {
        $pin = $host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip);
        $timeout = config()->integer('launchroom.dashboard.probe_timeout_seconds');

        try {
            $response = $this->http
                ->withOptions([
                    'allow_redirects' => false,
                    'proxy' => '',
                    'verify' => ! Hostname::isLocal($host),
                    'curl' => [CURLOPT_RESOLVE => [$pin]],
                ])
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->accept('application/json')
                ->get('https://'.$host.self::PATH, ['nonce' => $nonce]);
        } catch (ConnectionException $e) {
            $tls = $this->isTls($e->getMessage());

            return [DashboardProbeData::failed($this->explain($e->getMessage()), $ip), $tls ? 1 : 0];
        } catch (Throwable) {
            return [DashboardProbeData::failed('The test request failed.', $ip), 0];
        }

        return [$this->verdict($response, $expected, $ip), 2];
    }

    private function verdict(Response $response, string $expected, string $ip): DashboardProbeData
    {
        if ($response->status() !== 200) {
            return DashboardProbeData::failed("The address answered HTTP {$response->status()} instead of this dashboard.", $ip);
        }

        $proof = $response->json('proof');

        if (! is_string($proof) || ! hash_equals($expected, $proof)) {
            return DashboardProbeData::failed('Something else answered at this address, not this dashboard.', $ip);
        }

        return DashboardProbeData::passed($ip);
    }

    private function isTls(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'ssl') || str_contains($error, 'certificate') || str_contains($error, 'tls');
    }

    private function explain(string $error): string
    {
        $tls = $this->isTls($error);
        $error = strtolower($error);

        return match (true) {
            $tls => 'HTTPS isn’t ready yet: the certificate is still being issued or isn’t valid.',
            str_contains($error, 'timed out') || str_contains($error, 'timeout') => 'The test request timed out. Is port 443 open on the main server?',
            default => 'Couldn’t connect over HTTPS. Is port 443 open on the main server?',
        };
    }

    /**
     * The operator's fixed fallback address (an IP, or a name from the
     * container's hosts file such as host.docker.internal).
     *
     * @return list<string>
     */
    private function probeAddresses(): array
    {
        $address = config('launchroom.dashboard.probe_address');

        if (! is_string($address) || $address === '') {
            return [];
        }

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return [$address];
        }

        $resolved = gethostbynamel($address);

        return $resolved === false ? [] : $resolved;
    }
}

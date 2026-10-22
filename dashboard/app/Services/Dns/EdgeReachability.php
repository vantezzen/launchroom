<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlGuard;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\Hostname;
use App\Support\Network\IpClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * HTTP reachability check of a hostname (docs/05 §5): does a request to it
 * arrive at one of our edge servers, whatever sits in between (a proxy or
 * CDN such as Cloudflare, a NAT port forward)?
 *
 * Every edge Caddy forwards `/.well-known/launchroom/check/<nonce>` on any
 * host to its launchroomd, which signs `launchroom-reachability/v1\n<host>\n<nonce>`
 * with its Ed25519 identity key. A fresh random nonce per check makes the
 * answer one-time; the signature must verify against a non-revoked server.
 *
 * SSRF rules (docs/09 R5): the request goes through SafeHttpClient, pinned
 * to the public addresses the public lookup returned (private and reserved
 * ones refused), no proxies, at most one redirect to the same URL over
 * https, short timeouts and a 4 KiB response cap.
 */
final readonly class EdgeReachability
{
    public const string PATH = '/.well-known/launchroom/check/';

    public const string MESSAGE_PREFIX = "launchroom-reachability/v1\n";

    private const int MAX_RESPONSE_BYTES = 4096;

    public function __construct(
        private Factory $http,
    ) {}

    /**
     * @param  list<string>  $addresses  where public DNS points the name
     */
    public function check(string $hostname, array $addresses): ReachabilityResult
    {
        if (! config()->boolean('launchroom.dns.reachability')) {
            return ReachabilityResult::failed('the HTTP check is turned off');
        }

        $hostname = Hostname::normalize($hostname);
        $public = array_values(array_filter($addresses, IpClassifier::isPublic(...)));

        if (! Hostname::isValid($hostname) || $public === []) {
            return ReachabilityResult::failed('the name has no public address to connect to');
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $path = self::PATH.$nonce;
        $client = new SafeHttpClient(
            http: $this->http,
            guard: new UrlGuard(new PinnedDnsResolver($public), ['http', 'https']),
            connectTimeoutSeconds: config()->integer('launchroom.dns.reachability_connect_timeout'),
            timeoutSeconds: config()->integer('launchroom.dns.reachability_timeout'),
            maxResponseBytes: self::MAX_RESPONSE_BYTES,
        );

        try {
            $response = $client->get("http://{$hostname}{$path}", headers: ['Accept' => 'application/json']);

            // A proxy that upgrades to HTTPS ("Always Use HTTPS"): follow
            // that one redirect, nothing else.
            if ($response->redirect()) {
                if ($response->header('Location') !== "https://{$hostname}{$path}") {
                    return ReachabilityResult::failed('the request was redirected elsewhere');
                }

                $response = $client->get("https://{$hostname}{$path}", headers: ['Accept' => 'application/json']);
            }
        } catch (UnsafeUrl) {
            return ReachabilityResult::failed('the name points at a private or reserved address');
        } catch (ResponseTooLarge) {
            return ReachabilityResult::failed('the answer was not launchroom’s check');
        } catch (ConnectionException) {
            return ReachabilityResult::failed('nothing answered over HTTP in time');
        } catch (Throwable) {
            return ReachabilityResult::failed('the HTTP request failed');
        }

        return $this->verify($response, $hostname, $nonce);
    }

    private function verify(Response $response, string $hostname, string $nonce): ReachabilityResult
    {
        if ($response->status() >= 520 && $response->status() <= 530) {
            return ReachabilityResult::failed("the proxy could not reach your server (HTTP {$response->status()})");
        }

        if ($response->status() !== 200) {
            return ReachabilityResult::failed("the answer was HTTP {$response->status()}, not launchroom’s check");
        }

        $body = json_decode($response->body(), true);
        $serverId = is_array($body) && is_string($body['serverId'] ?? null) ? $body['serverId'] : null;
        $signature = is_array($body) && is_string($body['signature'] ?? null) ? base64_decode($body['signature'], true) : false;

        if ($serverId === null || preg_match('/^srv_[0-9A-HJKMNP-TV-Z]{26}$/D', $serverId) !== 1 || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ReachabilityResult::failed('the answer was not launchroom’s check');
        }

        $server = Server::query()
            ->where('public_id', $serverId)
            ->whereNull('revoked_at')
            ->where('status', '!=', ServerStatus::Revoked->value)
            ->first();
        $key = $server instanceof Server ? PublicKeyFormat::parseEd25519($server->signing_public_key) : null;

        if (! $server instanceof Server || $key === null || $key === ''
            || ! sodium_crypto_sign_verify_detached($signature, self::MESSAGE_PREFIX.$hostname."\n".$nonce, $key)) {
            return ReachabilityResult::failed('the answer did not come from one of your servers');
        }

        return ReachabilityResult::reached($server->public_id, $server->name);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlGuard;
use App\Support\Images\RegistryLayout;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Reads private images from an upstream registry (Docker Registry HTTP API
 * v2, D91): sign-in check, manifests and blobs. Every request goes through
 * the SafeHttpClient rules (docs/09 R5: https only, public addresses only,
 * resolved once and pinned, no proxies, no automatic redirects).
 *
 * Auth follows the registry's challenge: anonymous first; on 401 a Bearer
 * challenge is answered by asking its realm (https, guard-checked) for a
 * token with Basic auth, a Basic challenge with Basic auth on every request.
 * Blob downloads follow up to 3 redirects (storage URLs), each checked
 * again, and the Authorization header only goes to the registry itself.
 * Every manifest and blob is checked against its sha256 digest.
 */
final readonly class UpstreamRegistry
{
    public const int MAX_MANIFEST_BYTES = 4 * 1024 * 1024;

    public const int MAX_BLOB_BYTES = 8 * 1024 * 1024 * 1024;

    private const int MAX_REDIRECTS = 3;

    private SafeHttpClient $small;

    private SafeHttpClient $large;

    public function __construct(
        private HttpFactory $http,
        UrlGuard $guard,
    ) {
        $this->small = new SafeHttpClient($http, $guard, 10, 60, self::MAX_MANIFEST_BYTES);
        $this->large = new SafeHttpClient($http, $guard, 10, 1800, self::MAX_BLOB_BYTES);
    }

    /**
     * Signs in to the registry (`GET /v2/` and its token flow).
     *
     * @throws UpstreamRegistryError
     */
    public function checkSignIn(UpstreamAuth $auth): void
    {
        $response = $this->send($auth, 'GET', $auth->baseUrl().'/v2/', '', [], $this->small);

        if ($response->status() === 401 || $response->status() === 403) {
            throw $this->refused($auth);
        }

        if (! $response->successful()) {
            throw $this->statusError($auth, $response->status(), null);
        }
    }

    /**
     * @throws UpstreamRegistryError
     */
    public function manifest(UpstreamAuth $auth, string $repository, string $reference): UpstreamManifest
    {
        $url = $auth->baseUrl().'/v2/'.$repository.'/manifests/'.rawurlencode($reference);
        $response = $this->send($auth, 'GET', $url, $this->scope($repository), ['Accept' => implode(', ', RegistryLayout::MANIFEST_ACCEPT)], $this->small);

        if (! $response->successful()) {
            throw $this->statusError($auth, $response->status(), $repository.(str_starts_with($reference, 'sha256:') ? '@' : ':').$reference);
        }

        $bytes = $response->body();
        $digest = 'sha256:'.hash('sha256', $bytes);

        if (str_starts_with($reference, 'sha256:') ? ! hash_equals($reference, $digest) : $this->headerDigestDiffers($response, $digest)) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "The registry sent {$repository} with a different digest than it named, so launchroom didn’t use it.");
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if (! in_array($type, RegistryLayout::MANIFEST_ACCEPT, true)) {
            $document = json_decode($bytes, true);
            $type = is_array($document) && is_string($document['mediaType'] ?? null) ? $document['mediaType'] : $type;
        }

        if (! in_array($type, RegistryLayout::MANIFEST_ACCEPT, true)) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "{$repository} isn’t a container image launchroom can run.");
        }

        return new UpstreamManifest($bytes, $type, $digest);
    }

    /**
     * Streams a blob into $path and checks its digest. Returns its size.
     *
     * @throws UpstreamRegistryError
     */
    public function downloadBlob(UpstreamAuth $auth, string $repository, string $digest, string $path, int $maxBytes): int
    {
        $url = $auth->baseUrl().'/v2/'.$repository.'/blobs/'.$digest;
        $origin = (string) parse_url($auth->baseUrl(), PHP_URL_HOST);
        $response = $this->send($auth, 'GET', $url, $this->scope($repository), [], $this->large, stream: true);

        for ($hops = 0; $response->redirect() && $hops < self::MAX_REDIRECTS; $hops++) {
            $location = $response->header('Location');
            $url = $this->absolute($url, $location);
            $sameHost = strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower($origin);
            $response = $sameHost
                ? $this->send($auth, 'GET', $url, $this->scope($repository), [], $this->large, stream: true)
                : $this->once('GET', $url, [], $this->large, stream: true);
        }

        if (! $response->successful()) {
            throw $this->statusError($auth, $response->status(), $repository.'@'.$digest);
        }

        $out = new LazyOpenStream($path, 'xb');
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);

                if ($chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, 'The image is larger than launchroom copies ('.intdiv($maxBytes, 1024 ** 3).' GB).');
                }

                hash_update($hash, $chunk);
                $out->write($chunk);
            }
        } catch (UpstreamRegistryError $e) {
            throw $e;
        } catch (Throwable) {
            throw new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, "The download of {$repository} from {$auth->host} broke off. Deploy again to retry.");
        } finally {
            $out->close();
        }

        if (! hash_equals($digest, 'sha256:'.hash_final($hash))) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "A layer of {$repository} from {$auth->host} didn’t match its digest, so launchroom didn’t use it.");
        }

        return $bytes;
    }

    private function scope(string $repository): string
    {
        return 'repository:'.$repository.':pull';
    }

    /**
     * One request to the registry with its auth; a 401 is answered once
     * through the challenge it carries.
     *
     * @param  array<string, string>  $headers
     *
     * @throws UpstreamRegistryError
     */
    private function send(UpstreamAuth $auth, string $method, string $url, string $scope, array $headers, SafeHttpClient $client, bool $stream = false): Response
    {
        $authorization = $auth->authorization($scope);
        $response = $this->once($method, $url, $authorization === null ? $headers : [...$headers, 'Authorization' => $authorization], $client, $stream);

        if ($response->status() !== 401 || $authorization !== null) {
            return $response;
        }

        $this->authenticate($auth, $response->header('WWW-Authenticate'), $scope);
        $authorization = $auth->authorization($scope);

        if ($authorization === null) {
            return $response;
        }

        return $this->once($method, $url, [...$headers, 'Authorization' => $authorization], $client, $stream);
    }

    /**
     * @throws UpstreamRegistryError
     */
    private function authenticate(UpstreamAuth $auth, string $challenge, string $scope): void
    {
        $challenge = trim($challenge);

        if (preg_match('/^basic\b/i', $challenge) === 1) {
            $auth->useBasic();

            return;
        }

        if (preg_match('/^bearer\s+(.*)$/is', $challenge, $matches) !== 1) {
            throw $this->refused($auth);
        }

        preg_match_all('/([a-z]+)="([^"]*)"/i', $matches[1], $pairs, PREG_SET_ORDER);
        $params = [];

        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }

        $realm = $params['realm'] ?? '';

        if (! str_starts_with(strtolower($realm), 'https://')) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "{$auth->host} asks to sign in somewhere launchroom doesn’t send passwords to (it must be an https address).");
        }

        $query = array_filter(['service' => $params['service'] ?? null, 'scope' => $scope !== '' ? $scope : null], is_string(...));
        $url = $realm.(str_contains($realm, '?') ? '&' : '?').http_build_query($query);
        $response = $this->once('GET', $url, ['Authorization' => $auth->basicHeader()], $this->small);

        if ($response->status() === 401 || $response->status() === 403) {
            throw $this->refused($auth);
        }

        if (! $response->successful()) {
            throw $this->statusError($auth, $response->status(), null);
        }

        $token = $response->json('token') ?? $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "{$auth->host} answered the sign-in without a token.");
        }

        $auth->remember($scope, $token);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws UpstreamRegistryError
     */
    private function once(string $method, string $url, array $headers, SafeHttpClient $client, bool $stream = false): Response
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        try {
            $target = $client->check($url);

            return $this->http
                ->withOptions([...$client->transportOptions($target), 'stream' => $stream])
                ->withUserAgent('launchroom')
                ->withHeaders($headers)
                ->send($method, $target->url);
        } catch (UnsafeUrl) {
            throw new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, "launchroom doesn’t connect to {$host}: it isn’t a public https address.");
        } catch (ResponseTooLarge) {
            throw new UpstreamRegistryError(UpstreamRegistryError::INVALID, "{$host} sent more than launchroom accepts for one request.");
        } catch (Throwable) {
            throw new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, "launchroom couldn’t reach {$host}. Check the address and try again.");
        }
    }

    private function refused(UpstreamAuth $auth): UpstreamRegistryError
    {
        return new UpstreamRegistryError(UpstreamRegistryError::UNAUTHORIZED, "The registry refused the sign-in for {$auth->username} on {$auth->host}. Check the username and token in Settings → Private registries.");
    }

    private function statusError(UpstreamAuth $auth, int $status, ?string $image): UpstreamRegistryError
    {
        $name = $image === null ? $auth->host : "{$auth->host}/{$image}";

        return match (true) {
            $status === 401 => $image === null ? $this->refused($auth) : new UpstreamRegistryError(UpstreamRegistryError::FORBIDDEN, "{$auth->username} has no access to {$name}. Check the image name, or give this account read access to it."),
            $status === 403 => new UpstreamRegistryError(UpstreamRegistryError::FORBIDDEN, "{$auth->username} has no access to {$name}. Check the image name, or give this account read access to it."),
            $status === 404 => new UpstreamRegistryError(UpstreamRegistryError::NOT_FOUND, "{$name} was not found. Check the name and tag."),
            $status === 429 => new UpstreamRegistryError(UpstreamRegistryError::RATE_LIMITED, "{$auth->host} is limiting requests right now. Deploy again in a few minutes."),
            default => new UpstreamRegistryError(UpstreamRegistryError::UNREACHABLE, "{$auth->host} answered HTTP {$status}. Try again later."),
        };
    }

    private function headerDigestDiffers(Response $response, string $digest): bool
    {
        $header = $response->header('Docker-Content-Digest');

        return $header !== '' && preg_match('/^sha256:[a-f0-9]{64}$/D', $header) === 1 && ! hash_equals($header, $digest);
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        $host = (string) parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').'/'.ltrim($location, '/');
    }
}

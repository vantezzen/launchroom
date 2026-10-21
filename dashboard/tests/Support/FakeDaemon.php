<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Daemon\HttpMessageSignature;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An in-process daemon that signs its requests exactly like the Go daemon
 * (protocol/SIGNING.md §2). The signing code is checked against the shared
 * vectors in HttpMessageSignatureTest.
 */
final class FakeDaemon
{
    public string $keyId = HttpMessageSignature::ENROLL_KEY_ID;

    private readonly string $secretKey;

    private readonly string $publicKey;

    public function __construct(?string $seed = null)
    {
        $keypair = sodium_crypto_sign_seed_keypair($seed ?? random_bytes(32));
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
    }

    public function signingKey(): string
    {
        return PublicKeyFormat::formatEd25519($this->publicKey);
    }

    public function as(string $keyId): self
    {
        $this->keyId = $keyId;

        return $this;
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, string>  $extraHeaders  override or add headers (HTTP names)
     * @return TestResponse<Response>
     */
    public function send(TestCase $test, string $method, string $uri, array|string|null $body = null, array $extraHeaders = [], ?int $created = null): TestResponse
    {
        $raw = match (true) {
            $body === null => '',
            is_string($body) => $body,
            default => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };

        $headers = HttpMessageSignature::sign(
            $method,
            $uri,
            $raw,
            $this->keyId,
            $this->secretKey,
            $created ?? now()->getTimestamp(),
            random_bytes(16),
        );

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        // launchroomd advertises its protocol versions on every request (docs/03 §10).
        foreach ([...$headers, 'X-Lr-Protocol-Versions' => 'v1', ...$extraHeaders] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $test->call($method, $uri, [], [], [], $server, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollBody(string $token, array $overrides = []): array
    {
        return [
            'apiVersion' => 'launchroom.vantezzen.io/v1',
            'kind' => 'EnrollRequest',
            'token' => $token,
            'signingKey' => $this->signingKey(),
            'sealingKey' => 'x25519:'.base64_encode(random_bytes(32)),
            'daemonVersion' => '0.1.0',
            'protocolVersions' => ['v1'],
            'host' => ['hostname' => 'server-1', 'os' => 'linux', 'arch' => 'arm64', 'cpus' => 4, 'memoryMb' => 8192],
            ...$overrides,
        ];
    }
}

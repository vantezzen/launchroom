<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/**
 * The new machine's side of a control-plane move (docs/14 §13.4), used by
 * `launchroom:move:receive` inside the new install's dashboard image. It
 * talks to the old dashboard named in the move token (or the address the
 * operator gives with --url), never to anything a remote party chose: this
 * runs on the operator's own new server, not in a request, so it doesn't go
 * through SafeHttpClient (the old dashboard may well be on a private
 * network).
 *
 * Every answer must carry a valid `X-LR-Signature` by the control plane's
 * key pinned from the token, and JSON answers `kind: launchroom-move/1`;
 * anything else is refused before it is used.
 */
final readonly class MoveReceiverClient
{
    public const string KIND = 'launchroom-move/1';

    /** @var non-empty-string */
    private string $signingKey;

    public function __construct(
        private string $url,
        private string $move,
        #[SensitiveParameter]
        private string $secret,
        string $signingKey,
        private ?string $caFile = null,
    ) {
        $raw = PublicKeyFormat::parseEd25519($signingKey);

        if ($raw === null || $raw === '') {
            throw new RuntimeException('The move token has no valid signing key.');
        }

        $this->signingKey = $raw;
    }

    /**
     * @param  array{publicKey: string, hostname: string, arch: string, version: string|null}  $receiver
     * @return array<string, mixed>
     */
    public function claim(array $receiver): array
    {
        return $this->json($this->request()->post('/move/v1/claim', [
            'move' => $this->move,
            'secret' => $this->secret,
            ...$receiver,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->json($this->request(true)->get("/move/v1/{$this->move}"));
    }

    public function chunk(int $index): string
    {
        return $this->bytes($this->request(true)->timeout(300)->get("/move/v1/{$this->move}/chunks/{$index}"));
    }

    public function keys(): string
    {
        return $this->bytes($this->request(true)->timeout(300)->get("/move/v1/{$this->move}/keys"));
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(string $url): array
    {
        return $this->json($this->request(true)->post("/move/v1/{$this->move}/complete", ['url' => $url]));
    }

    /**
     * @return array<string, mixed>
     */
    public function abort(string $reason): array
    {
        return $this->json($this->request(true)->post("/move/v1/{$this->move}/abort", ['reason' => mb_substr($reason, 0, 500)]));
    }

    private function request(bool $authenticated = false): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->url, '/'))
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->retry(3, 2000, static fn (\Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            ->withOptions(['verify' => $this->caFile ?? true]);

        return $authenticated ? $request->withToken($this->secret) : $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = $this->verified($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ($decoded['kind'] ?? null) !== self::KIND) {
            throw new RuntimeException('The old dashboard sent an unexpected answer.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function bytes(Response $response): string
    {
        return $this->verified($response);
    }

    private function verified(Response $response): string
    {
        if (! $response->successful()) {
            $error = $response->json('error');

            throw new RuntimeException(match (true) {
                is_string($error) => $error,
                $response->status() === 403 => 'The old dashboard refused the move token.',
                $response->status() >= 300 && $response->status() < 400 => 'The old dashboard answered with a redirect. Pass its address with --move-url.',
                default => "The old dashboard answered {$response->status()}.",
            });
        }

        $body = $response->body();
        $header = $response->header('X-LR-Signature');

        if (preg_match('/^kid=[A-Za-z0-9._-]{1,64}, sig=([A-Za-z0-9+\/]+={0,2})$/D', $header, $match) !== 1) {
            throw new RuntimeException('The old dashboard’s answer is not signed.');
        }

        $signature = base64_decode($match[1], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $body, $this->signingKey)) {
            throw new RuntimeException('The old dashboard’s answer is not signed with the key in the move token. Nothing was changed.');
        }

        return $body;
    }
}

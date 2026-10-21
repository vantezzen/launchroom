<?php

declare(strict_types=1);

namespace App\Support\Previews;

use SensitiveParameter;

/**
 * Signed, expiring tokens of preview protection (docs/06 §5, docs/09 R20a):
 * `v1.<base64url(json claims)>.<base64url(HMAC-SHA256)>`.
 *
 * Kinds (claim `k`, part of the MAC so one kind can never pass as another):
 * - `exchange`: minted by the dashboard for a signed-in member, ≤ 2 min,
 *   single use (`n` nonce), bound to one preview host; swapped for a cookie.
 * - `cookie`: the value of the preview host's own cookie (host-only),
 *   bound to the host and environment; `u` (user id) or `l` (share link).
 * - `share`: the token inside a share link, bound to the environment (every
 *   host of the preview), revocable through its share link row (`l`).
 *
 * Pure: the key is passed in (derived from APP_KEY by the caller).
 */
final readonly class PreviewToken
{
    public const string KIND_EXCHANGE = 'exchange';

    public const string KIND_COOKIE = 'cookie';

    public const string KIND_SHARE = 'share';

    private const string VERSION = 'v1';

    /**
     * @param  string  $environment  environment public id
     * @param  string|null  $host  lowercase hostname (no port); null for share tokens
     */
    public function __construct(
        public string $kind,
        public string $environment,
        public ?string $host,
        public int $expiresAt,
        public ?int $userId = null,
        public ?string $shareLink = null,
        public ?string $nonce = null,
    ) {}

    public function sign(#[SensitiveParameter] string $key): string
    {
        $claims = array_filter([
            'k' => $this->kind,
            'e' => $this->environment,
            'h' => $this->host,
            'x' => $this->expiresAt,
            'u' => $this->userId,
            'l' => $this->shareLink,
            'n' => $this->nonce,
        ], static fn (mixed $value): bool => $value !== null);

        $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::VERSION.'.'.$payload.'.'.$this->encode(hash_hmac('sha256', self::VERSION.'.'.$payload, $key, true));
    }

    /**
     * The token's claims if the MAC verifies, the kind matches and it hasn't
     * expired at $now; null otherwise (fail closed, constant-time MAC check).
     */
    public static function verify(string $token, string $kind, #[SensitiveParameter] string $key, int $now): ?self
    {
        if (strlen($token) > 2048) {
            return null;
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        $mac = self::decode($parts[2]);

        if ($mac === null || ! hash_equals(hash_hmac('sha256', self::VERSION.'.'.$parts[1], $key, true), $mac)) {
            return null;
        }

        $json = self::decode($parts[1]);
        $claims = $json === null ? null : json_decode($json, true, 4);

        if (! is_array($claims) || ($claims['k'] ?? null) !== $kind || ! is_string($claims['e'] ?? null) || ! is_int($claims['x'] ?? null)) {
            return null;
        }

        if ($claims['x'] <= $now) {
            return null;
        }

        $host = $claims['h'] ?? null;
        $user = $claims['u'] ?? null;
        $link = $claims['l'] ?? null;
        $nonce = $claims['n'] ?? null;

        return new self(
            $kind,
            $claims['e'],
            is_string($host) ? $host : null,
            $claims['x'],
            is_int($user) ? $user : null,
            is_string($link) ? $link : null,
            is_string($nonce) ? $nonce : null,
        );
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }

        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}

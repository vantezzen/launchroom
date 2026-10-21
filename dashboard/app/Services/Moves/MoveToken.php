<?php

declare(strict_types=1);

namespace App\Services\Moves;

use App\Support\Crypto\PublicKeyFormat;
use SensitiveParameter;

/**
 * The one-time move token of Settings → Move launchroom (docs/14 §13.4):
 *
 *   lrmv1.<base64url dashboard URL>.<cpm_…>.<base64url 32-byte secret>.<base64url Ed25519 public key>
 *
 * Everything `install.sh --move` needs on the new machine: where the old
 * dashboard is, which move, the secret that authenticates the new machine's
 * requests (only its SHA-256 is stored) and the control plane's signing key,
 * which the new machine pins: every answer of the old dashboard is signed
 * with it (X-LR-Signature), and the key bundle it receives must contain it.
 *
 * The comparison code both sides show binds the new machine's X25519 key to
 * the move: someone who intercepts the token and claims with their own key
 * produces a different code than the one in the new machine's terminal.
 */
final class MoveToken
{
    public const string PREFIX = 'lrmv1';

    private const string CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @return array{token: string, hash: string}
     */
    public static function issue(string $url, string $moveId, string $signingPublicKey): array
    {
        $secret = random_bytes(32);
        $raw = PublicKeyFormat::parseEd25519($signingPublicKey) ?? throw new \InvalidArgumentException('Not an Ed25519 public key.');

        return [
            'token' => implode('.', [self::PREFIX, self::b64($url), $moveId, self::b64($secret), self::b64($raw)]),
            'hash' => self::hashSecret(self::b64($secret)),
        ];
    }

    /**
     * @return array{url: string, move: string, secret: string, signingKey: string}|null
     */
    public static function parse(#[SensitiveParameter] string $token): ?array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 5 || $parts[0] !== self::PREFIX || preg_match('/^cpm_[0-9A-HJKMNP-TV-Z]{26}$/D', $parts[2]) !== 1) {
            return null;
        }

        $url = self::unb64($parts[1]);
        $secret = self::unb64($parts[3]);
        $key = self::unb64($parts[4]);

        if ($url === null || preg_match('/^https?:\/\/[A-Za-z0-9.:\[\]-]+$/D', $url) !== 1 || $secret === null || strlen($secret) !== 32 || $key === null || strlen($key) !== 32) {
            return null;
        }

        return ['url' => $url, 'move' => $parts[2], 'secret' => $parts[3], 'signingKey' => PublicKeyFormat::formatEd25519($key)];
    }

    /**
     * SHA-256 (hex) of the secret as it appears in the token.
     */
    public static function hashSecret(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', 'launchroom-move-secret|'.$secret);
    }

    /**
     * `XXXX-XXXX`: eight characters from SHA-256 over the move and the new
     * machine's public key (no 0/O/1/I/L).
     */
    public static function claimCode(string $moveId, string $receiverPublicKey): string
    {
        $digest = hash('sha256', 'launchroom-move-claim|'.$moveId.'|'.$receiverPublicKey, true);
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[ord($digest[$i]) % strlen(self::CODE_ALPHABET)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,400}$/D', $value) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($value, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}

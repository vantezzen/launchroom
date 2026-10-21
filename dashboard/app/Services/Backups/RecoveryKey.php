<?php

declare(strict_types=1);

namespace App\Services\Backups;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * The recovery passphrase (docs/14 §4) and the control-plane key bundle it
 * protects.
 *
 * The passphrase itself is never stored. Argon2id (libsodium crypto_pwhash)
 * stretches it into a 32-byte seed, from which an X25519 key pair is
 * derived (crypto_box_seed_keypair). The dashboard keeps only the public key
 * and the KDF parameters, so scheduled backups can seal the key bundle
 * (crypto_box_seal) without knowing the passphrase, and only someone who
 * knows it can derive the secret key to open a bundle. Deriving the public
 * key again and comparing it (hash_equals) verifies a passphrase.
 *
 * Bundle file format (JSON, "launchroom-recovery-bundle/1"):
 *   {format, kdf: {alg: "argon2id13", salt (base64), opslimit, memlimit},
 *    publicKey: "x25519:<base64>", sealed: base64(crypto_box_seal(json))}
 *
 * Sealing is anonymous (anyone with the public key can seal), so the
 * passphrase alone gives confidentiality, not integrity. Format 2
 * ("launchroom-recovery-bundle/2", review L5) adds `binding` (the backup run
 * and the restic id of its database snapshot) and `mac`, an HMAC-SHA256
 * over format, binding, public key and sealed bytes with a key derived from
 * the passphrase ({@see macKey()}): the dashboard stores that key encrypted
 * with APP_KEY when the passphrase is set, so scheduled backups can
 * authenticate bundles; a restore derives it from the passphrase, so
 * someone with the bucket and the recovery kit but without the passphrase
 * can neither forge a bundle nor pair it with another database dump.
 */
final class RecoveryKey
{
    public const string FORMAT = 'launchroom-recovery-bundle/1';

    public const string FORMAT_BOUND = 'launchroom-recovery-bundle/2';

    private const string MAC_CONTEXT = 'launchroom-recovery-bundle-mac/1';

    public const int MIN_PASSPHRASE_LENGTH = 12;

    /**
     * @return array{alg: string, salt: string, opslimit: int, memlimit: int}
     */
    public static function newKdf(int $opslimit, int $memlimit): array
    {
        return [
            'alg' => 'argon2id13',
            'salt' => base64_encode(random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES)),
            'opslimit' => $opslimit,
            'memlimit' => $memlimit,
        ];
    }

    /**
     * Public key ("x25519:<base64>") for a passphrase and KDF parameters.
     *
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     */
    public static function publicKey(#[SensitiveParameter] string $passphrase, array $kdf): string
    {
        $keypair = self::keypair($passphrase, $kdf);
        $public = sodium_crypto_box_publickey($keypair);
        sodium_memzero($keypair);

        return 'x25519:'.base64_encode($public);
    }

    /**
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     */
    public static function verify(#[SensitiveParameter] string $passphrase, array $kdf, string $expectedPublicKey): bool
    {
        return hash_equals($expectedPublicKey, self::publicKey($passphrase, $kdf));
    }

    /**
     * The bundle MAC key for a passphrase (32 bytes): a keyed BLAKE2b of the
     * same Argon2id seed, under its own context.
     *
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     */
    public static function macKey(#[SensitiveParameter] string $passphrase, array $kdf): string
    {
        $seed = self::seed($passphrase, $kdf);

        try {
            return sodium_crypto_generichash(self::MAC_CONTEXT, $seed, 32);
        } finally {
            sodium_memzero($seed);
        }
    }

    /**
     * Seals $plaintext into a bundle file for the stored public key; with a
     * MAC key, bound to the backup run and its database snapshot (format 2).
     *
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     * @param  array{run: string, databaseSnapshot: string}|null  $binding
     */
    public static function seal(#[SensitiveParameter] string $plaintext, string $publicKey, array $kdf, ?array $binding = null, #[SensitiveParameter] ?string $macKey = null): string
    {
        $raw = self::rawPublicKey($publicKey);
        $sealed = base64_encode(sodium_crypto_box_seal($plaintext, $raw));

        if ($binding === null || $macKey === null) {
            return json_encode([
                'format' => self::FORMAT,
                'kdf' => $kdf,
                'publicKey' => $publicKey,
                'sealed' => $sealed,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        }

        $binding = ['run' => $binding['run'], 'databaseSnapshot' => $binding['databaseSnapshot']];

        return json_encode([
            'format' => self::FORMAT_BOUND,
            'kdf' => $kdf,
            'publicKey' => $publicKey,
            'binding' => $binding,
            'sealed' => $sealed,
            'mac' => base64_encode(self::mac($macKey, $binding, $publicKey, $sealed)),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Opens a bundle file with the passphrase.
     *
     * @throws RuntimeException when the bundle is malformed, not authentic or the passphrase is wrong
     */
    public static function open(string $bundle, #[SensitiveParameter] string $passphrase): string
    {
        return self::openBound($bundle, $passphrase)[0];
    }

    /**
     * Opens a bundle file with the passphrase and returns its plaintext and
     * binding (null for format 1, which has none). A format-2 bundle whose
     * MAC does not verify is refused before it is opened.
     *
     * @return array{0: string, 1: array{run: string, databaseSnapshot: string}|null}
     *
     * @throws RuntimeException when the bundle is malformed, not authentic or the passphrase is wrong
     */
    public static function openBound(string $bundle, #[SensitiveParameter] string $passphrase): array
    {
        try {
            $decoded = json_decode($bundle, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The key bundle is not valid JSON.');
        }

        if (! is_array($decoded) || ! in_array($decoded['format'] ?? null, [self::FORMAT, self::FORMAT_BOUND], true) || ! is_array($decoded['kdf'] ?? null)
            || ! is_string($decoded['publicKey'] ?? null) || ! is_string($decoded['sealed'] ?? null)) {
            throw new RuntimeException('The key bundle has an unknown format.');
        }

        $kdf = self::kdf($decoded['kdf']);
        // One Argon2id run for both the MAC key and the key pair.
        $seed = self::seed($passphrase, $kdf);

        try {
            return self::openWithSeed($decoded, $decoded['publicKey'], $decoded['sealed'], $seed);
        } finally {
            sodium_memzero($seed);
        }
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array{0: string, 1: array{run: string, databaseSnapshot: string}|null}
     */
    private static function openWithSeed(array $decoded, string $publicKey, string $sealedText, #[SensitiveParameter] string $seed): array
    {
        $binding = null;

        if ($decoded['format'] === self::FORMAT_BOUND) {
            $raw = $decoded['binding'] ?? null;
            $mac = is_string($decoded['mac'] ?? null) ? base64_decode($decoded['mac'], true) : false;

            if (! is_array($raw) || ! is_string($raw['run'] ?? null) || ! is_string($raw['databaseSnapshot'] ?? null) || $mac === false) {
                throw new RuntimeException('The key bundle has an unknown format.');
            }

            $binding = ['run' => $raw['run'], 'databaseSnapshot' => $raw['databaseSnapshot']];
            $macKey = sodium_crypto_generichash(self::MAC_CONTEXT, $seed, 32);

            try {
                if (! hash_equals(self::mac($macKey, $binding, $publicKey, $sealedText), $mac)) {
                    throw new RuntimeException('The key bundle is not authentic for this passphrase (wrong passphrase, or the backup was tampered with).');
                }
            } finally {
                sodium_memzero($macKey);
            }
        }

        $keypair = sodium_crypto_box_seed_keypair($seed);

        try {
            if (! hash_equals($publicKey, 'x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)))) {
                throw new RuntimeException('The recovery passphrase is not correct for this backup.');
            }

            $sealed = base64_decode($sealedText, true);
            $plaintext = $sealed === false ? false : sodium_crypto_box_seal_open($sealed, $keypair);

            if ($plaintext === false) {
                throw new RuntimeException('The key bundle could not be decrypted (tampered or truncated).');
            }

            return [$plaintext, $binding];
        } finally {
            sodium_memzero($keypair);
        }
    }

    /**
     * @param  array{run: string, databaseSnapshot: string}  $binding
     */
    private static function mac(#[SensitiveParameter] string $macKey, array $binding, string $publicKey, string $sealed): string
    {
        return hash_hmac('sha256', implode("\n", [self::FORMAT_BOUND, $binding['run'], $binding['databaseSnapshot'], $publicKey, $sealed]), $macKey, true);
    }

    /**
     * @param  array<mixed>  $kdf
     * @return array{alg: string, salt: string, opslimit: int, memlimit: int}
     */
    public static function kdf(array $kdf): array
    {
        $alg = $kdf['alg'] ?? null;
        $salt = $kdf['salt'] ?? null;
        $ops = $kdf['opslimit'] ?? null;
        $mem = $kdf['memlimit'] ?? null;

        if ($alg !== 'argon2id13' || ! is_string($salt) || ! is_int($ops) || ! is_int($mem)
            || $ops < SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE || $ops > 16
            || $mem < SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE || $mem > 4 * 1024 * 1024 * 1024) {
            throw new RuntimeException('The key bundle has unsupported key-derivation parameters.');
        }

        return ['alg' => $alg, 'salt' => $salt, 'opslimit' => $ops, 'memlimit' => $mem];
    }

    /**
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     */
    private static function keypair(#[SensitiveParameter] string $passphrase, array $kdf): string
    {
        $seed = self::seed($passphrase, $kdf);

        try {
            return sodium_crypto_box_seed_keypair($seed);
        } finally {
            sodium_memzero($seed);
        }
    }

    /**
     * @param  array{alg: string, salt: string, opslimit: int, memlimit: int}  $kdf
     */
    private static function seed(#[SensitiveParameter] string $passphrase, array $kdf): string
    {
        if ($passphrase === '') {
            throw new InvalidArgumentException('The recovery passphrase is empty.');
        }

        $salt = base64_decode($kdf['salt'], true);

        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('The key-derivation salt is malformed.');
        }

        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_BOX_SEEDBYTES,
            $passphrase,
            $salt,
            $kdf['opslimit'],
            $kdf['memlimit'],
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private static function rawPublicKey(string $publicKey): string
    {
        $raw = str_starts_with($publicKey, 'x25519:') ? base64_decode(substr($publicKey, 7), true) : false;

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('The recovery public key is malformed.');
        }

        return $raw;
    }
}

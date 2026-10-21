<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Services\Crypto\Exceptions\DecryptionFailed;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * A 256-bit symmetric key for XChaCha20-Poly1305 (IETF) authenticated
 * encryption. Used both as the KEK and as per-project DEKs.
 *
 * Never serializable, never printed: `var_dump`, exceptions and queue
 * payloads only ever see "[redacted]".
 *
 * Ciphertext format: "lr1:" + base64(nonce[24] || ciphertext || tag[16]).
 * The version prefix is also bound into the associated data, so a ciphertext
 * can't be reinterpreted under a future format.
 */
final readonly class SecretKey
{
    public const int BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    private const string VERSION = 'lr1';

    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const int TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    private function __construct(
        #[SensitiveParameter]
        private string $bytes,
    ) {}

    public static function generate(): self
    {
        return new self(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    public static function fromBytes(#[SensitiveParameter] string $bytes): self
    {
        if (strlen($bytes) !== self::BYTES) {
            throw new InvalidArgumentException('A secret key must be exactly '.self::BYTES.' bytes.');
        }

        return new self($bytes);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext, string $associatedData): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->bindVersion($associatedData),
            $nonce,
            $this->bytes,
        );

        return self::VERSION.':'.base64_encode($nonce.$ciphertext);
    }

    /**
     * @throws DecryptionFailed when the ciphertext is malformed, tampered with,
     *                          encrypted under another key, or the associated
     *                          data doesn't match
     */
    public function decrypt(string $ciphertext, string $associatedData): string
    {
        $prefix = self::VERSION.':';

        if (! str_starts_with($ciphertext, $prefix)) {
            throw DecryptionFailed::unsupportedFormat();
        }

        $raw = base64_decode(substr($ciphertext, strlen($prefix)), true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw DecryptionFailed::malformed();
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, self::NONCE_BYTES),
            $this->bindVersion($associatedData),
            substr($raw, 0, self::NONCE_BYTES),
            $this->bytes,
        );

        if ($plaintext === false) {
            throw DecryptionFailed::authenticationFailed();
        }

        return $plaintext;
    }

    /**
     * Raw key material. Only for wrapping by {@see Envelope} and for writing
     * the key file; never log or persist the result unencrypted elsewhere.
     *
     * @internal
     */
    public function reveal(): string
    {
        return $this->bytes;
    }

    /**
     * Stable, non-secret identifier of this key (for `kek_id` columns).
     */
    public function id(): string
    {
        return substr(hash_hmac('sha256', 'launchroom-key-id', $this->bytes), 0, 16);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['bytes' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Secret keys cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Secret keys cannot be unserialized.');
    }

    private function bindVersion(string $associatedData): string
    {
        return self::VERSION.'|'.$associatedData;
    }
}

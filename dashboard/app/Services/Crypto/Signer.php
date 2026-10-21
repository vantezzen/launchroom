<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use App\Support\AuditChain;
use App\Support\Crypto\PublicKeyFormat;
use LogicException;
use SensitiveParameter;

/**
 * Signs dashboard → daemon response bodies with the control-plane Ed25519 key
 * (protocol/SIGNING.md §3): `X-LR-Signature: kid=<kid>, sig=<base64>` over
 * the exact body bytes, and audit checkpoints over their domain-separated
 * message (docs/09 R22, R34). Used for nothing else.
 */
final readonly class Signer
{
    public const string HEADER = 'X-LR-Signature';

    /**
     * @param  non-empty-string  $secretKey
     */
    private function __construct(
        public string $kid,
        #[SensitiveParameter]
        private string $secretKey,
        private string $publicKey,
    ) {}

    public static function fromSeed(string $kid, #[SensitiveParameter] string $seed): self
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException('An Ed25519 seed must be 32 bytes.');
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return new self($kid, sodium_crypto_sign_secretkey($keypair), sodium_crypto_sign_publickey($keypair));
    }

    /**
     * The `X-LR-Signature` header value for $body.
     */
    public function signatureHeader(string $body): string
    {
        return 'kid='.$this->kid.', sig='.base64_encode(sodium_crypto_sign_detached($body, $this->secretKey));
    }

    /**
     * Signs an audit checkpoint (docs/09 R34, protocol/SIGNING.md §7). The
     * message must carry the checkpoint prefix, so a signature made here can
     * never pass as a signed daemon payload (launchroomd only acts on JSON
     * documents). Returns the standard base64 signature.
     */
    public function signAuditCheckpoint(string $message): string
    {
        if (! str_starts_with($message, AuditChain::CHECKPOINT_PREFIX)) {
            throw new \InvalidArgumentException('Not an audit checkpoint message.');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $this->secretKey));
    }

    /**
     * `ed25519:<base64>`, the format daemons pin (LR_CP_KEY).
     */
    public function publicKey(): string
    {
        return PublicKeyFormat::formatEd25519($this->publicKey);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid, 'secretKey' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Signing keys cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Signing keys cannot be unserialized.');
    }
}

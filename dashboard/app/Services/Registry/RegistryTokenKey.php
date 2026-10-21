<?php

declare(strict_types=1);

namespace App\Services\Registry;

/**
 * The ES256 key pair that signs registry tokens (docs/05 §6). The private
 * PEM never leaves this object except to the JWT signer: it is not
 * serializable and not dumped.
 */
final readonly class RegistryTokenKey
{
    /**
     * @param  non-empty-string  $privatePem
     * @param  non-empty-string  $publicPem
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $privatePem,
        public string $publicPem,
    ) {}

    /**
     * The private key in PEM form, for the JWT signer only.
     *
     * @return non-empty-string
     */
    public function privatePem(): string
    {
        return $this->privatePem;
    }

    /**
     * Stable key id: hex SHA-256 of the DER public key, truncated (JWT `kid`).
     *
     * @return non-empty-string
     */
    public function kid(): string
    {
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->publicPem) ?? '', true);

        return 'k'.substr(hash('sha256', $der === false ? $this->publicPem : $der), 0, 15);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['kid' => $this->kid(), 'privatePem' => '[redacted]'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Registry token keys are not serializable.');
    }
}

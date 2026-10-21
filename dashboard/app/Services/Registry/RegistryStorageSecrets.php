<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Models\Registry\RegistryStorage;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use JsonException;
use SensitiveParameter;

/**
 * Envelope encryption of the registry's S3 credentials (docs/09 R13): a data
 * key wrapped by the KEK (context `registry_storage:instance`), the value
 * XChaCha20-Poly1305 with associated data `registry_storage|instance|credentials`.
 * A fresh data key on every save.
 */
final readonly class RegistryStorageSecrets
{
    private const string WRAP_CONTEXT = 'registry_storage:instance';

    private const string ASSOCIATED_DATA = 'registry_storage|instance|credentials';

    public function __construct(
        private Envelope $envelope,
    ) {}

    public function seal(
        RegistryStorage $storage,
        #[SensitiveParameter] string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
    ): void {
        $dek = $this->envelope->generateDataKey();

        $storage->wrapped_dek = $this->envelope->wrap($dek, self::WRAP_CONTEXT);
        $storage->kek_id = $this->envelope->kekId();
        $storage->credentials = $dek->encrypt(
            json_encode(['accessKeyId' => $accessKeyId, 'secretAccessKey' => $secretAccessKey], JSON_THROW_ON_ERROR),
            self::ASSOCIATED_DATA,
        );
    }

    /**
     * @return array{accessKeyId: string, secretAccessKey: string}
     *
     * @throws DecryptionFailed
     */
    public function reveal(RegistryStorage $storage): array
    {
        $json = $this->envelope->unwrap($storage->wrapped_dek, self::WRAP_CONTEXT)->decrypt($storage->credentials, self::ASSOCIATED_DATA);

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DecryptionFailed::malformed();
        }

        if (! is_array($decoded) || ! is_string($decoded['accessKeyId'] ?? null) || ! is_string($decoded['secretAccessKey'] ?? null)) {
            throw DecryptionFailed::malformed();
        }

        return ['accessKeyId' => $decoded['accessKeyId'], 'secretAccessKey' => $decoded['secretAccessKey']];
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(RegistryStorage $storage, Envelope $from, Envelope $to): void
    {
        $storage->wrapped_dek = $from->rewrap($storage->wrapped_dek, self::WRAP_CONTEXT, $to);
        $storage->kek_id = $to->kekId();
    }
}

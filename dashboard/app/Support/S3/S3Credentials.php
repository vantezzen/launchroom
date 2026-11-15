<?php

declare(strict_types=1);

namespace App\Support\S3;

use App\Exceptions\DecryptionFailed;
use LogicException;
use SensitiveParameter;

/**
 * An S3 access key pair, in memory only: stored sealed as the JSON object
 * `{accessKeyId, secretAccessKey}`. Never serializable and redacted in dumps
 * (docs/09 R12, R16).
 */
final readonly class S3Credentials
{
    public function __construct(
        #[SensitiveParameter]
        public string $accessKeyId,
        #[SensitiveParameter]
        public string $secretAccessKey,
    ) {}

    /**
     * @param  array<string, string>  $values
     *
     * @throws DecryptionFailed when a key is missing
     */
    public static function of(#[SensitiveParameter] array $values): self
    {
        return new self(
            $values['accessKeyId'] ?? throw DecryptionFailed::malformed(),
            $values['secretAccessKey'] ?? throw DecryptionFailed::malformed(),
        );
    }

    /**
     * @return array{accessKeyId: string, secretAccessKey: string}
     */
    public function toArray(): array
    {
        return ['accessKeyId' => $this->accessKeyId, 'secretAccessKey' => $this->secretAccessKey];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessKeyId' => '[redacted]', 'secretAccessKey' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('S3 credentials cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('S3 credentials cannot be unserialized.');
    }
}

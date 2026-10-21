<?php

declare(strict_types=1);

namespace App\Services\Backups;

use LogicException;
use SensitiveParameter;

/**
 * Decrypted destination secrets, in memory only. Never serializable and
 * redacted in dumps, logs and exception traces (docs/09 R12, R16).
 */
final readonly class DestinationCredentials
{
    public function __construct(
        #[SensitiveParameter]
        public string $accessKeyId,
        #[SensitiveParameter]
        public string $secretAccessKey,
        #[SensitiveParameter]
        public string $repositoryPassword,
    ) {}

    /**
     * The environment restic reads (docs/14 §7: credentials via env, never argv).
     *
     * @return array<string, string>
     */
    public function resticEnv(?string $region): array
    {
        return [
            'AWS_ACCESS_KEY_ID' => $this->accessKeyId,
            'AWS_SECRET_ACCESS_KEY' => $this->secretAccessKey,
            'RESTIC_PASSWORD' => $this->repositoryPassword,
            'AWS_DEFAULT_REGION' => $region ?? 'us-east-1',
        ];
    }

    /**
     * Values to mask in any process output we surface.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->accessKeyId, $this->secretAccessKey, $this->repositoryPassword], static fn (string $s): bool => strlen($s) >= 4));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accessKeyId' => '[redacted]', 'secretAccessKey' => '[redacted]', 'repositoryPassword' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Destination credentials cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Destination credentials cannot be unserialized.');
    }
}

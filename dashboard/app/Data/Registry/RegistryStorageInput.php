<?php

declare(strict_types=1);

namespace App\Data\Registry;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Put the registry's storage on an S3-compatible bucket (docs/14 §9.11).
 * Credentials are optional when the storage is already configured (blank =
 * keep the stored ones).
 */
final class RegistryStorageInput extends Data
{
    public function __construct(
        #[Max(512)]
        public string $endpoint,
        #[Min(3), Max(63)]
        public string $bucket,
        #[Max(32)]
        public ?string $region = null,
        #[Max(256)]
        public ?string $prefix = null,
        #[Max(256), SensitiveParameter]
        public ?string $accessKeyId = null,
        #[Max(512), SensitiveParameter]
        public ?string $secretAccessKey = null,
        public bool $forcePathStyle = false,
        public bool $allowPrivateNetwork = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->endpoint, 'bucket' => $this->bucket, 'credentials' => '[redacted]'];
    }
}

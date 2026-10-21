<?php

declare(strict_types=1);

namespace App\Data\Backups;

use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Create or update an S3-compatible backup destination (docs/14 §3).
 * Credentials are optional on update (blank = keep). The repository
 * password is generated unless one is given to connect an existing restic
 * repository.
 */
final class BackupDestinationInput extends Data
{
    public function __construct(
        #[Min(1), Max(100)]
        public string $name,
        #[Max(512)]
        public string $endpoint,
        #[Min(3), Max(63)]
        public string $bucket,
        #[Max(32)]
        public ?string $region = null,
        #[Max(256)]
        public string $prefix = '',
        #[Max(256), SensitiveParameter]
        public ?string $accessKeyId = null,
        #[Max(512), SensitiveParameter]
        public ?string $secretAccessKey = null,
        #[Min(16), Max(256), SensitiveParameter]
        public ?string $repositoryPassword = null,
        public bool $allowPrivateNetwork = false,
        public bool $makeDefault = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'endpoint' => $this->endpoint, 'bucket' => $this->bucket, 'credentials' => '[redacted]'];
    }
}

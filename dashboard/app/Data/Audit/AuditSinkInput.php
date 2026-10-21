<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Enums\Audit\AuditSinkKind;
use App\Services\Audit\AuditSinkSettings;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Add an audit log destination (docs/09 R34). Kind-specific rules are
 * checked by {@see AuditSinkSettings}. Secret fields
 * are named so they are never flashed as old input.
 */
final class AuditSinkInput extends Data
{
    public function __construct(
        public AuditSinkKind $kind,
        #[Max(80)]
        public ?string $name = null,
        // syslog
        #[Max(253)]
        public ?string $host = null,
        #[Min(1), Max(65535)]
        public ?int $port = null,
        #[Max(16384)]
        public ?string $caCertificate = null,
        // webhook
        #[Max(2048), SensitiveParameter]
        public ?string $secretUrl = null,
        // s3
        #[Max(512)]
        public ?string $endpoint = null,
        #[Max(63)]
        public ?string $bucket = null,
        #[Max(32)]
        public ?string $region = null,
        #[Max(200)]
        public ?string $prefix = null,
        #[Max(256), SensitiveParameter]
        public ?string $accessKeyId = null,
        #[Max(512), SensitiveParameter]
        public ?string $secretAccessKey = null,
        #[Min(0), Max(3650)]
        public ?int $retentionDays = null,
        public ?string $lockMode = null,
        public bool $allowPrivateNetwork = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'name' => $this->name, 'credentials' => '[redacted]'];
    }
}

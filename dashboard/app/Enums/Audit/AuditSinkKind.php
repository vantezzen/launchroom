<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * Where audit entries are forwarded (docs/09 R34).
 */
enum AuditSinkKind: string
{
    case Syslog = 'syslog';
    case Webhook = 'webhook';
    case S3 = 's3';

    public function label(): string
    {
        return match ($this) {
            self::Syslog => 'Syslog over TLS',
            self::Webhook => 'Webhook',
            self::S3 => 'S3 bucket',
        };
    }

    /** Most entries per delivery. */
    public function batchSize(): int
    {
        return match ($this) {
            self::Syslog => 500,
            self::Webhook => 200,
            self::S3 => 1000,
        };
    }

    public function hasSecret(): bool
    {
        return $this !== self::Syslog;
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Audit\AuditSinkKind;
use App\Enums\Audit\AuditSinkStatus;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * One audit log destination in Settings → Security (docs/09 R34). Never a
 * credential: `target` is the host (and bucket) only.
 */
final class AuditSinkData extends Data
{
    public function __construct(
        public string $id,
        public AuditSinkKind $kind,
        public string $name,
        public ?string $target,
        public AuditSinkStatus $status,
        /** Entries not delivered yet. */
        public int $behind,
        /** Time of the newest delivered entry. */
        public ?CarbonInterface $deliveredThroughAt,
        public ?CarbonInterface $lastSuccessAt,
        public ?string $lastError,
        public int $failures,
        public ?CarbonInterface $nextAttemptAt,
        public bool $privateNetwork,
        /** S3: whether the bucket has Object Lock (null: not tested yet). */
        public ?bool $objectLock,
        public ?int $retentionDays,
        public ?string $lockMode,
    ) {}
}

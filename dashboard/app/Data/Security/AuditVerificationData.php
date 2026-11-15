<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Data\Audit\AuditChainVerification;
use Spatie\LaravelData\Data;

/**
 * `instance_settings.audit_verification`: the last audit log check (docs/09 R34): "Audit log verified ✓ up to
 * <verifiedUpTo>" or the problem it found. `verifiedUpTo` is the newest
 * entry a valid signed checkpoint anchors (null: no checkpoint yet).
 */
final class AuditVerificationData extends Data
{
    public function __construct(
        public bool $ok,
        /** `full` (from the first entry) or `incremental` (from the last checkpoint). */
        public string $mode,
        public string $checkedAt,
        public int $entries,
        public ?string $verifiedUpTo,
        public ?string $problem,
        public ?int $problemEntry,
    ) {}

    public static function fromResult(AuditChainVerification $result): self
    {
        return new self(
            ok: $result->valid,
            mode: $result->incremental ? 'incremental' : 'full',
            checkedAt: now()->toIso8601ZuluString(),
            entries: $result->checked,
            verifiedUpTo: $result->verifiedUpTo?->toIso8601ZuluString(),
            problem: $result->valid ? null : (string) $result->message,
            problemEntry: $result->valid ? null : $result->firstInvalidId,
        );
    }

    /**
     * For members who aren't instance owners: whether it holds, not where it broke.
     */
    public function withoutDetails(): self
    {
        return new self($this->ok, $this->mode, $this->checkedAt, $this->entries, $this->verifiedUpTo, null, null);
    }
}

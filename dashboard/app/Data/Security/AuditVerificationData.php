<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The last audit log check (docs/09 R34): "Audit log verified ✓ up to
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

    /**
     * @param  array<string, mixed>|null  $status
     */
    public static function fromStatus(?array $status): ?self
    {
        if ($status === null || ! is_string($status['checkedAt'] ?? null)) {
            return null;
        }

        $problem = is_array($status['problem'] ?? null) ? $status['problem'] : null;

        return new self(
            ok: ($status['ok'] ?? false) === true,
            mode: is_string($status['mode'] ?? null) ? $status['mode'] : 'full',
            checkedAt: $status['checkedAt'],
            entries: is_int($status['entries'] ?? null) ? $status['entries'] : 0,
            verifiedUpTo: is_string($status['verifiedUpTo'] ?? null) ? $status['verifiedUpTo'] : null,
            problem: $problem !== null && is_string($problem['message'] ?? null) ? $problem['message'] : null,
            problemEntry: $problem !== null && is_int($problem['eventId'] ?? null) ? $problem['eventId'] : null,
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

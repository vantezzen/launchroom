<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Actions\Audit\VerifyAuditChain;
use App\Enums\Audit\AuditChainProblem;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The outcome of {@see VerifyAuditChain} (docs/09 R34).
 * `checked` counts entries that verified (in this run); `verifiedUpTo` is
 * the time of the newest entry a valid checkpoint anchors.
 */
final class AuditChainVerification extends Data
{
    public function __construct(
        public bool $valid,
        public int $checked,
        public ?int $firstInvalidId = null,
        public ?AuditChainProblem $problem = null,
        public ?string $message = null,
        public ?int $checkpointId = null,
        public int $checkpoints = 0,
        public ?CarbonImmutable $verifiedUpTo = null,
        public ?int $lastEventId = null,
        public bool $incremental = false,
    ) {}
}

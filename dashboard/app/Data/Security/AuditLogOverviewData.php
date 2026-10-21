<?php

declare(strict_types=1);

namespace App\Data\Security;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Settings → Security → Audit log (docs/09 R34): the last check, the
 * newest checkpoint, the key outside verifiers need and the forwarding
 * destinations with their delivery status.
 */
final class AuditLogOverviewData extends Data
{
    /**
     * @param  list<AuditSinkData>  $sinks
     */
    public function __construct(
        public ?AuditVerificationData $verification,
        public int $entries,
        public ?CarbonInterface $lastCheckpointAt,
        /** `ed25519:<base64>`, the key checkpoints are signed with. */
        public string $publicKey,
        public string $keyId,
        public array $sinks,
        public bool $canAdd,
    ) {}
}

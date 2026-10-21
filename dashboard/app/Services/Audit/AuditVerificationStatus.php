<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Data\Audit\AuditChainVerification;
use App\Enums\Audit\AuditChainProblem;
use App\Models\InstanceSettings;

/**
 * The last audit chain verification (`instance_settings.audit_verification`,
 * docs/09 R34), shown as "Audit log verified ✓ up to <time>". An
 * incremental run that passes keeps the problem of nothing (a later full
 * run is what proves the older part again).
 */
final class AuditVerificationStatus
{
    public function record(AuditChainVerification $result): void
    {
        $status = [
            'ok' => $result->valid,
            'mode' => $result->incremental ? 'incremental' : 'full',
            'checkedAt' => now()->toIso8601ZuluString(),
            'entries' => $result->checked,
            'checkpoints' => $result->checkpoints,
            'verifiedUpTo' => $result->verifiedUpTo?->toIso8601ZuluString(),
            'lastEventId' => $result->lastEventId,
        ];

        if (! $result->valid) {
            $status['problem'] = [
                'kind' => $result->problem instanceof AuditChainProblem ? $result->problem->value : 'edited',
                'eventId' => $result->firstInvalidId,
                'checkpointId' => $result->checkpointId,
                'message' => (string) $result->message,
            ];
        }

        InstanceSettings::current()->forceFill(['audit_verification' => $status])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $status = InstanceSettings::current()->audit_verification;

        return is_array($status) ? $status : null;
    }
}

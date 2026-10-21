<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Security\AuditLogOverviewData;
use App\Data\Security\AuditSinkData;
use App\Data\Security\AuditVerificationData;
use App\Enums\Audit\AuditSinkKind;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\AuditSink;
use App\Services\Audit\AuditVerificationStatus;
use App\Services\Crypto\Signer;

/**
 * Settings → Security → Audit log (docs/09 R34): the last verification and
 * the forwarding sinks with their delivery status.
 */
final readonly class AuditLogOverview
{
    public function __construct(
        private AuditVerificationStatus $status,
        private Signer $signer,
    ) {}

    public function get(): AuditLogOverviewData
    {
        $max = AuditEvent::query()->max('id');
        $lastEventId = is_numeric($max) ? (int) $max : 0;
        $sinks = AuditSink::query()->orderBy('id')->get();

        return new AuditLogOverviewData(
            verification: AuditVerificationData::fromStatus($this->status->current()),
            entries: AuditEvent::query()->count(),
            lastCheckpointAt: AuditCheckpoint::query()->orderByDesc('id')->first()?->created_at,
            publicKey: $this->signer->publicKey(),
            keyId: $this->signer->kid,
            sinks: array_values($sinks->map(static fn (AuditSink $sink): AuditSinkData => new AuditSinkData(
                id: $sink->public_id,
                kind: $sink->kind,
                name: $sink->name,
                target: $sink->target_hint,
                status: $sink->status(),
                behind: $sink->cursor_event_id >= $lastEventId ? 0 : AuditEvent::query()->where('id', '>', $sink->cursor_event_id)->count(),
                deliveredThroughAt: $sink->delivered_through_at,
                lastSuccessAt: $sink->last_success_at,
                lastError: $sink->last_error,
                failures: $sink->failures,
                nextAttemptAt: $sink->next_attempt_at,
                privateNetwork: $sink->allow_private_network,
                objectLock: $sink->kind === AuditSinkKind::S3 && is_bool($sink->config['objectLock'] ?? null) ? $sink->config['objectLock'] : null,
                retentionDays: $sink->configInt('retentionDays'),
                lockMode: $sink->configString('lockMode'),
            ))->all()),
            canAdd: $sinks->count() < AuditSink::MAX_SINKS,
        );
    }
}

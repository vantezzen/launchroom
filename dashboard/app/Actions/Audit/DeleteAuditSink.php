<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\AuditSink;
use Illuminate\Support\Facades\DB;

/**
 * Stops forwarding to a destination and forgets its credentials (docs/09
 * R34). What was delivered stays there. Audited.
 */
final readonly class DeleteAuditSink
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(AuditSink $sink, AuditActor $actor): void
    {
        DB::transaction(function () use ($sink, $actor): void {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::AuditSinkDeleted,
                actor: $actor,
                subjectType: 'audit_sink',
                subjectId: $sink->public_id,
                meta: ['kind' => $sink->kind->value, 'target' => $sink->target_hint, 'deliveredThrough' => $sink->cursor_event_id],
            ));

            $sink->delete();
        });
    }
}

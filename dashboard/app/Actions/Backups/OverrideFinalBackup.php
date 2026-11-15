<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionRequestStatus;
use App\Models\Backups\DeletionRequest;
use App\Models\User;

/**
 * Marks a deletion request as going ahead without its final backup
 * (docs/14 §9.13). Callers authorize and hold the request's row lock.
 */
final readonly class OverrideFinalBackup
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(DeletionRequest $request, ?User $user, AuditActor $actor): void
    {
        if ($request->status->allowsDeletion()) {
            return;
        }

        $request->forceFill(['overridden_by' => $user?->id, 'overridden_at' => now()]);
        $request->transitionTo(DeletionRequestStatus::Overridden, $request->error);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::FinalBackupSkipped,
            actor: $actor,
            teamId: $request->team_id,
            subjectType: $request->subject_type,
            subjectId: $request->subject_public_id,
            meta: ['request' => $request->public_id, 'name' => $request->subject_name, 'reason' => $request->error],
        ));
    }
}

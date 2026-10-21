<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;

final readonly class RevokeInvitation
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(TeamInvitation $invitation, AuditActor $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $invitation->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::InvitationRevoked,
                actor: $actor,
                teamId: $invitation->team_id,
                subjectType: 'team_invitation',
                subjectId: $invitation->public_id,
                meta: ['email' => $invitation->email],
            ));
        });
    }
}

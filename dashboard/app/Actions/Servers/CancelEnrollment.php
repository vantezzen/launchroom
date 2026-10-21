<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\ServerEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Removes a server that was added but never connected (docs/08 §3.9): the
 * enrolment row and with it the join token's hash are deleted, so the
 * install command stops working (EnrollServer finds no token and fails
 * closed). An enrolment that a server already used is refused: that server
 * has its own key and leaves through RevokeServer.
 */
final readonly class CancelEnrollment
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServerEnrollment $enrollment, AuditActor $actor): void
    {
        DB::transaction(function () use ($enrollment, $actor): void {
            // Same row lock as EnrollServer: a server connecting right now wins or loses atomically.
            $enrollment = ServerEnrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();

            if ($enrollment->used_at !== null) {
                throw DomainRuleViolation::conflict('This server already connected. Remove it from its page instead.');
            }

            if ($enrollment->is_control_plane) {
                throw DomainRuleViolation::forbidden('The dashboard’s own server can’t be removed here.');
            }

            $enrollment->delete();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerEnrollmentCanceled,
                actor: $actor,
                teamId: $enrollment->team_id,
                subjectType: 'server_enrollment',
                subjectId: $enrollment->public_id,
                meta: [
                    'name' => $enrollment->name,
                    'expired' => ! $enrollment->expires_at->isFuture(),
                ],
            ));
        });
    }
}

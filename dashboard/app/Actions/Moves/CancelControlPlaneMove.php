<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Servers\CancelEnrollment;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\ServerEnrollment;
use Illuminate\Support\Facades\DB;

/**
 * Cancels Settings → Move launchroom before the hand-over (docs/14 §13.4):
 * the token stops working, an unused join token is withdrawn, and the
 * workload move is cancelled (services being copied go back; services
 * that already run on the new server stay there). The new server, if it
 * joined, stays a regular server of this dashboard; revoke it on its page
 * if it should go.
 */
final readonly class CancelControlPlaneMove
{
    public function __construct(
        private CancelServerMove $cancelMove,
        private CancelEnrollment $cancelEnrollment,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, AuditActor $actor): ControlPlaneMove
    {
        DB::transaction(function () use ($move, $actor): void {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if (! $move->status->canTransitionTo(ControlPlaneMoveStatus::Cancelled)) {
                throw DomainRuleViolation::conflict($move->status === ControlPlaneMoveStatus::HandedOver
                    ? 'The new dashboard already took over.'
                    : 'Abort the hand-over first.');
            }

            $serverMove = $move->server_move_id === null ? null : ServerMove::query()->find($move->server_move_id);

            if ($serverMove instanceof ServerMove && in_array($serverMove->status, [ServerMoveStatus::Running, ServerMoveStatus::Ready], true)) {
                $this->cancelMove->handle($serverMove, $actor, fromControlPlaneMove: true);
            }

            $enrollment = $move->enrollment_id === null ? null : ServerEnrollment::query()->find($move->enrollment_id);

            if ($enrollment instanceof ServerEnrollment && $enrollment->used_at === null) {
                $this->cancelEnrollment->handle($enrollment, $actor);
            }

            $move->transitionTo(ControlPlaneMoveStatus::Cancelled);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneMoveCancelled,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['move' => $move->public_id],
            ));
        });

        return $move->refresh();
    }
}

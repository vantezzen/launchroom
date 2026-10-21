<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\InstanceFreeze;
use Illuminate\Support\Facades\DB;

/**
 * Aborts a hand-over that hasn't completed (docs/14 §13.4): the new
 * server's installer reports a failure, or an owner aborts it because the
 * new server never picked it up. This dashboard takes changes again and the
 * move is back at "ready to hand over". Only safe while no new dashboard
 * runs on the new server: the installer starts it only after it restored
 * everything, and reports back right after.
 */
final readonly class AbortHandover
{
    public function __construct(
        private HandoverStore $store,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, ?string $reason, AuditActor $actor): ControlPlaneMove
    {
        return DB::transaction(function () use ($move, $reason, $actor): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if (! in_array($move->status, [ControlPlaneMoveStatus::HandingOver, ControlPlaneMoveStatus::HandoverReady], true)) {
                throw DomainRuleViolation::conflict('No hand-over is in progress.');
            }

            $this->store->delete($move->public_id);
            InstanceFreeze::thaw();
            $move->frozen_at = null;
            $move->handover = null;
            $move->transitionTo(ControlPlaneMoveStatus::ReadyToHandOver, $reason === null ? null : mb_substr('The hand-over was aborted: '.$reason, 0, 2048));

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneHandoverAborted,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: array_filter(['move' => $move->public_id, 'reason' => $reason === null ? null : mb_substr($reason, 0, 200)]),
            ));

            return $move;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ServerMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ServerMove;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a server move (docs/14 §13.2). Services that already run on the
 * target stay there; one that is being copied goes back to the source,
 * which still has its data as it was when it stopped; the rest never
 * leave. The source stops draining. The control-plane half of
 * Settings → Move launchroom is cancelled from there.
 */
final readonly class CancelServerMove
{
    public function __construct(
        private RecordAuditEvent $audit,
        private AdvanceServerMoves $advance,
    ) {}

    public function handle(ServerMove $move, AuditActor $actor, bool $fromControlPlaneMove = false): ServerMove
    {
        DB::transaction(function () use ($move, $actor, $fromControlPlaneMove): void {
            $move = ServerMove::query()->whereKey($move->id)->with('source')->lockForUpdate()->firstOrFail();

            if ($move->control_plane && ! $fromControlPlaneMove) {
                throw DomainRuleViolation::conflict('This move is part of moving launchroom: cancel it in Settings → Move launchroom.');
            }

            if (! in_array($move->status, [ServerMoveStatus::Running, ServerMoveStatus::Ready], true)) {
                throw DomainRuleViolation::conflict('This move has ended.');
            }

            $move->transitionTo(ServerMoveStatus::Cancelling);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveCancelled,
                actor: $actor,
                teamId: $move->team_id,
                subjectType: 'server',
                subjectId: $move->source->public_id,
                meta: ['move' => $move->public_id, 'stage' => 'requested'],
            ));
        });

        $this->advance->handle($move->id);

        return $move->refresh();
    }
}

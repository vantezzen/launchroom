<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Data\Audit\AuditActor;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Step 3 of Settings → Move launchroom (docs/14 §13.4): every app, database
 * and volume on the dashboard's server moves to the new server, exactly
 * like a server move ({@see StartServerMove}, `control_plane`), while the
 * dashboard itself keeps running here. The hand-over comes after.
 */
final readonly class MoveControlPlaneWorkloads
{
    public function __construct(
        private StartServerMove $start,
    ) {}

    public function handle(ControlPlaneMove $move, bool $backupFirst, User $user, AuditActor $actor): ControlPlaneMove
    {
        return DB::transaction(function () use ($move, $backupFirst, $user, $actor): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->with(['source', 'target'])->lockForUpdate()->firstOrFail();

            if ($move->status !== ControlPlaneMoveStatus::Joined) {
                throw DomainRuleViolation::conflict('The new server hasn’t joined yet.');
            }

            if (! $move->source instanceof Server || ! $move->target instanceof Server) {
                throw DomainRuleViolation::conflict('A server of this move is gone. Cancel the move and start again.');
            }

            $serverMove = $this->start->handle($move->source, $move->target, $backupFirst, $user, $actor, controlPlane: true);
            $move->server_move_id = $serverMove->id;
            $move->transitionTo(ControlPlaneMoveStatus::Moving);

            return $move;
        });
    }
}

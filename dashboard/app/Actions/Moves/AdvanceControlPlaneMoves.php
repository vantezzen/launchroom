<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Servers\ApproveServer;
use App\Data\Audit\AuditActor;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Enums\ServerStatus;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves Settings → Move launchroom forward on the old dashboard (docs/14
 * §13.4), from the moves tick and from the page:
 *
 * - an unclaimed token expires after 24 hours;
 * - joining → joined once the new machine's launchroomd enrolled (it is
 *   approved right away: the owner confirmed its code);
 * - moving ↔ ready_to_hand_over follows the workload move.
 */
final readonly class AdvanceControlPlaneMoves
{
    public function __construct(
        private ApproveServer $approve,
    ) {}

    public function handle(): void
    {
        $move = ControlPlaneMove::open();

        if (! $move instanceof ControlPlaneMove) {
            return;
        }

        DB::transaction(function () use ($move): void {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            match ($move->status) {
                ControlPlaneMoveStatus::WaitingForServer => $move->token_expires_at->isPast()
                    ? $move->transitionTo(ControlPlaneMoveStatus::Cancelled, 'The move token expired before a new server used it.')
                    : null,
                ControlPlaneMoveStatus::Joining => $this->joined($move),
                ControlPlaneMoveStatus::Moving, ControlPlaneMoveStatus::ReadyToHandOver => $this->follow($move),
                default => null,
            };
        });
    }

    private function joined(ControlPlaneMove $move): void
    {
        $enrollment = $move->enrollment_id === null ? null : ServerEnrollment::query()->find($move->enrollment_id);
        $server = $enrollment?->server_id === null ? null : Server::query()->find($enrollment->server_id);

        if (! $server instanceof Server) {
            return;
        }

        if ($server->status === ServerStatus::PendingApproval) {
            $approver = User::query()->find($move->approved_by ?? $move->created_by);

            if ($approver instanceof User) {
                $this->approve->handle($server, $approver, AuditActor::system());
            }
        }

        $move->target_server_id = $server->id;
        // The join token was used: nothing sealed is kept.
        $move->sealed_join_token = null;
        $move->transitionTo(ControlPlaneMoveStatus::Joined);
    }

    private function follow(ControlPlaneMove $move): void
    {
        $serverMove = $move->server_move_id === null ? null : ServerMove::query()->find($move->server_move_id);

        if (! $serverMove instanceof ServerMove) {
            return;
        }

        if ($move->status === ControlPlaneMoveStatus::Moving && $serverMove->status === ServerMoveStatus::Ready) {
            $move->transitionTo(ControlPlaneMoveStatus::ReadyToHandOver);
        } elseif ($move->status === ControlPlaneMoveStatus::ReadyToHandOver && $serverMove->status === ServerMoveStatus::Running) {
            $move->transitionTo(ControlPlaneMoveStatus::Moving);
        }
    }
}

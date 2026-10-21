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
use App\Services\Moves\ServerRetirement;
use Illuminate\Support\Facades\DB;

/**
 * The last step of a server move (docs/14 §13.2): once everything runs on
 * the target and DNS points there, the source is retired. The target takes
 * over the source's roles and labels (its own labels win; allow-lists that
 * name the source stand for it from now on), and the source gets an empty
 * "decommission" desired state (no workloads, no routes, no system
 * containers; its Docker volumes are never deleted) and is revoked, the
 * way "Replace server" retires a dead one. Its launchroomd fetches that
 * state and stops what it ran; afterwards its key is refused.
 *
 * {@see ServerRetirement} also retires the old control-plane server when
 * the new dashboard takes over (docs/14 §13.4).
 */
final readonly class RetireMovedServer
{
    public function __construct(
        private ServerRetirement $retirement,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServerMove $move, AuditActor $actor): ServerMove
    {
        return DB::transaction(function () use ($move, $actor): ServerMove {
            $move = ServerMove::query()->whereKey($move->id)->with(['source', 'target'])->lockForUpdate()->firstOrFail();

            if ($move->control_plane) {
                throw DomainRuleViolation::conflict('The dashboard’s server is retired when the new one takes over (Settings → Move launchroom).');
            }

            if ($move->status !== ServerMoveStatus::Ready) {
                throw DomainRuleViolation::conflict('Retire the server once every service runs on '.$move->target->name.'.');
            }

            $this->retirement->retire($move->source, $move->target, $actor);
            $move->transitionTo(ServerMoveStatus::Completed);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerMoveCompleted,
                actor: $actor,
                teamId: $move->team_id,
                subjectType: 'server',
                subjectId: $move->source->public_id,
                meta: ['move' => $move->public_id, 'target' => $move->target->public_id],
            ));

            return $move;
        });
    }
}

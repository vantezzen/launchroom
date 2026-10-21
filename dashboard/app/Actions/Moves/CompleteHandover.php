<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\InstanceFreeze;
use App\Services\Moves\ServerRetirement;
use Illuminate\Support\Facades\DB;

/**
 * The new server reports that its dashboard runs (docs/14 §13.4): this
 * dashboard stays frozen for good and points every page at the new
 * address, the hand-over file is deleted, and this machine's own server
 * is retired here too (an empty desired state, then revoked), so its
 * launchroomd stops the edge and the system containers: the old machine
 * no longer serves anything and can be deleted. (The new dashboard
 * retired it in its own copy already.)
 */
final readonly class CompleteHandover
{
    public function __construct(
        private HandoverStore $store,
        private ServerRetirement $retirement,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ControlPlaneMove $move, string $newUrl): ControlPlaneMove
    {
        if (preg_match('/^https?:\/\/[A-Za-z0-9.:\[\]-]{1,253}$/D', $newUrl) !== 1) {
            throw DomainRuleViolation::on('url', 'Expected the new dashboard’s address.');
        }

        return DB::transaction(function () use ($move, $newUrl): ControlPlaneMove {
            $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

            if ($move->status === ControlPlaneMoveStatus::HandedOver) {
                return $move;
            }

            if ($move->status !== ControlPlaneMoveStatus::HandoverReady) {
                throw DomainRuleViolation::conflict('No hand-over is waiting to complete.');
            }

            $this->store->delete($move->public_id);
            $move->new_url = $newUrl;
            $move->handed_over_at = now();
            $move->transitionTo(ControlPlaneMoveStatus::HandedOver);
            InstanceFreeze::moved($newUrl);

            $source = $move->source_server_id === null ? null : Server::query()->find($move->source_server_id);
            $target = $move->target_server_id === null ? null : Server::query()->find($move->target_server_id);

            if ($source instanceof Server && $target instanceof Server && $source->status !== ServerStatus::Revoked) {
                try {
                    $this->retirement->retire($source, $target, AuditActor::system());
                } catch (DomainRuleViolation $e) {
                    // Something still runs here (it moved meanwhile?): keep serving it.
                    report($e);
                }
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneHandedOver,
                actor: AuditActor::system(),
                subjectType: 'instance',
                subjectId: 'instance',
                meta: ['move' => $move->public_id, 'to' => $newUrl],
            ));

            return $move;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\InstanceSettings;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Moves\ServerMove;
use App\Models\Server;
use App\Queries\MissingImages;
use App\Services\Instance\DashboardOrigin;
use App\Services\Moves\ServerRetirement;
use Illuminate\Support\Facades\DB;

/**
 * The new dashboard takes over (docs/14 §13.4), run once by
 * `install.sh --move` (`launchroom:move:takeover`) right after it restored
 * the final dump and started the stack on the new machine:
 *
 * - the new machine's server (enrolled before the dump, so it is in it)
 *   becomes the control-plane server; the old one is retired (its roles and
 *   labels move over, it gets an empty state and is revoked: if its
 *   launchroomd ever reaches this dashboard, it stops everything);
 * - the workload move completes and the restored freeze is lifted;
 * - images of running deployments that aren't in this registry are marked
 *   lost, so their next deploy builds from the recorded commit instead of
 *   pulling (running containers keep their local copies);
 * - a new recovery kit is asked for when the dashboard's address changed;
 * - the post-move checklist opens (DNS, images, other servers, the old machine).
 *
 * @return array{move: string, server: string, previous: string, missingImages: int}
 */
final readonly class TakeOverControlPlane
{
    public function __construct(
        private ServerRetirement $retirement,
        private MissingImages $images,
        private DashboardOrigin $origin,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return array{move: string, server: string, previous: string, missingImages: int}
     */
    public function handle(AuditActor $actor): array
    {
        $result = DB::transaction(function () use ($actor): array {
            $move = ControlPlaneMove::query()
                ->whereIn('status', [ControlPlaneMoveStatus::HandingOver->value, ControlPlaneMoveStatus::HandoverReady->value])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $move instanceof ControlPlaneMove) {
                throw DomainRuleViolation::conflict('This database holds no hand-over to take over.');
            }

            $old = $move->source_server_id === null ? null : Server::query()->find($move->source_server_id);
            $new = $move->target_server_id === null ? null : Server::query()->find($move->target_server_id);

            if (! $old instanceof Server || ! $new instanceof Server) {
                throw DomainRuleViolation::conflict('The servers of this move are missing from the database.');
            }

            $new->forceFill(['is_control_plane' => true])->save();
            $this->retirement->retire($old, $new, $actor);

            $serverMove = $move->server_move_id === null ? null : ServerMove::query()->whereKey($move->server_move_id)->lockForUpdate()->first();

            if ($serverMove instanceof ServerMove && $serverMove->status === ServerMoveStatus::Ready) {
                $serverMove->transitionTo(ServerMoveStatus::Completed);
            }

            $previousUrl = $move->handover['canonicalUrl'] ?? null;
            $move->handed_over_at = now();
            $move->new_url = $this->origin->canonical();
            $move->sealed_join_token = null;
            $move->handover = null;
            $move->transitionTo(ControlPlaneMoveStatus::HandedOver);

            $settings = InstanceSettings::lockedForUpdate();
            $changed = is_string($previousUrl) && $previousUrl !== $this->origin->canonical();
            $settings->forceFill([
                'frozen_at' => null,
                'frozen_reason' => null,
                'moved_to' => null,
                'move_checklist' => [
                    'startedAt' => now()->toIso8601String(),
                    'moveId' => $move->public_id,
                    'previousServerId' => $old->id,
                    'previousUrl' => is_string($previousUrl) ? $previousUrl : null,
                    'newServerId' => $new->id,
                ],
                // The kit names the dashboard's address: a new one goes with a new kit.
                'recovery_kit_confirmed_at' => $changed ? null : $settings->recovery_kit_confirmed_at,
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ControlPlaneTakenOver,
                actor: $actor,
                teamId: $new->team_id,
                subjectType: 'instance',
                subjectId: 'instance',
                meta: array_filter(['move' => $move->public_id, 'server' => $new->public_id, 'previous' => $old->public_id, 'url' => $move->new_url, 'urlChanged' => $changed]),
            ));

            event(new NodeStateInvalidated(array_values(Server::query()->whereNull('revoked_at')->pluck('id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all())));

            return ['move' => $move->public_id, 'server' => $new->public_id, 'previous' => $old->public_id];
        });

        // Outside the transaction: one registry request per running deployment.
        $missing = 0;

        foreach ($this->images->scan()['missing'] as $deployment) {
            Deployment::query()
                ->where('service_instance_id', $deployment->service_instance_id)
                ->where('image', $deployment->image)
                ->update(['build_key' => null, 'image_pruned_at' => now()]);
            $missing++;
        }

        return [...$result, 'missingImages' => $missing];
    }
}

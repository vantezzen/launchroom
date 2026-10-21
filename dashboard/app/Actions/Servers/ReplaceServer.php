<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\RestoreRun;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Models\User;
use App\Models\Volume;
use App\Queries\ServerReplacementPlan;
use Illuminate\Support\Facades\DB;

/**
 * "Replace server" (docs/14 §5.3; the control-plane case runs from recovery
 * mode, §5.2): a dead server's role is taken over by another approved,
 * online server of the team.
 *
 * In one transaction:
 * 1. the replacement inherits the dead server's roles and labels (and the
 *    control-plane flag);
 * 2. volumes pinned to the dead server move to the replacement (their
 *    data is restored from backups, they start empty otherwise) and the dead
 *    server's placements are dropped;
 * 3. the dead server gets an empty "decommission" NodeState, is revoked
 *    (keys denied, pinned tasks cancelled, mesh node removed) and marked
 *    replaced: if it ever comes back, the only thing its key still fetches
 *    is that empty state, so it stops what it ran (AuthenticateDaemon);
 * 4. for each stateful service chosen in $restore, an in-place restore of
 *    its latest backup is queued (one per database or volume; it starts as
 *    soon as the service runs on the replacement).
 *
 * After commit, the stateful services are redeployed (pinned to the
 * replacement through their volumes); stateless ones follow once every
 * restore finished ({@see AdvanceServerReplacements}), so apps start after
 * their databases.
 */
final readonly class ReplaceServer
{
    public function __construct(
        private ServerReplacementPlan $plan,
        private RenderNodeState $render,
        private RevokeServer $revoke,
        private RecordAuditEvent $audit,
        private AdvanceServerReplacements $advance,
    ) {}

    /**
     * @param  list<string>  $restore  public ids of the stateful service instances to restore from their latest backup
     */
    public function handle(Server $old, Server $new, array $restore, ?User $user, AuditActor $actor, bool $recovery = false): ServerReplacement
    {
        if ($old->team_id !== $new->team_id || $old->id === $new->id) {
            throw DomainRuleViolation::on('replacement', 'Pick another server of the same team as the replacement.');
        }

        if ($new->status !== ServerStatus::Online) {
            throw DomainRuleViolation::on('replacement', "{$new->name} must be online to take over.");
        }

        if ($old->status === ServerStatus::Revoked || $old->status === ServerStatus::PendingApproval) {
            throw DomainRuleViolation::on('server', "{$old->name} is not an active server.");
        }

        if (! $recovery && $old->status === ServerStatus::Online) {
            throw DomainRuleViolation::on('server', "{$old->name} is online. Only an unreachable or offline server can be replaced.");
        }

        if (! $recovery && $old->is_control_plane) {
            throw DomainRuleViolation::on('server', 'The control-plane server is replaced from recovery mode, after restoring the control plane with `install.sh --restore` on a new server.');
        }

        $replacement = DB::transaction(function () use ($old, $new, $restore, $user, $actor, $recovery): ServerReplacement {
            $old = Server::query()->whereKey($old->id)->lockForUpdate()->firstOrFail();
            $new = Server::query()->whereKey($new->id)->lockForUpdate()->firstOrFail();

            if (ServerReplacement::query()->where('old_server_id', $old->id)->where('status', ServerReplacementStatus::Running->value)->exists()) {
                throw DomainRuleViolation::conflict("{$old->name} is already being replaced.");
            }

            $plan = $this->plan->for($old);
            $roles = array_values(array_unique([...array_map(static fn (ServerRole $role): string => $role->value, $old->roles->all()), ...array_map(static fn (ServerRole $role): string => $role->value, $new->roles->all())]));

            $new->forceFill([
                'roles' => $roles,
                'labels' => [...$old->labels, ...$new->labels],
                'is_control_plane' => $new->is_control_plane || $old->is_control_plane,
            ])->save();

            Volume::query()->where('server_id', $old->id)->update(['server_id' => $new->id]);
            /** @var list<int> $peers */
            $peers = Placement::query()->where('server_id', '!=', $old->id)->where('team_id', $old->team_id)->distinct()->pluck('server_id')->all();
            Placement::query()->where('server_id', $old->id)->delete();

            // Decommission: an empty desired state while it is still approved,
            // with the mesh client off and no credentials (review L9). A
            // render that is refused would leave the old, full state as the
            // latest one: the replacement then fails (nothing committed).
            $old->forceFill(['roles' => [], 'labels' => [], 'is_control_plane' => false, 'replaced_by_server_id' => $new->id])->save();

            if ($this->render->handle($old)->failed()) {
                throw DomainRuleViolation::conflict("{$old->name} could not be given an empty state. Check its server page and try again.");
            }

            $this->revoke->handle($old, $actor);
            Server::query()->whereKey($old->id)->update(['replaced_by_server_id' => $new->id, 'replaced_at' => now()]);

            $replacement = ServerReplacement::query()->create([
                'team_id' => $old->team_id,
                'old_server_id' => $old->id,
                'new_server_id' => $new->id,
                'recovery' => $recovery,
                'created_by' => $user?->id,
                'services' => [],
            ]);

            $services = [];

            foreach ($plan as $entry) {
                $instance = $entry['instance'];
                $backup = $entry['restore'];
                $runs = [];
                $restoreInfo = null;

                if ($backup !== null && in_array($instance->public_id, $restore, true)) {
                    $destination = BackupSchedule::query()->where('service_instance_id', $instance->id)->first()?->destination()->first()
                        ?? BackupDestination::defaultFor($instance->team_id);

                    if ($destination instanceof BackupDestination) {
                        $targets = $backup['subject'] === BackupSubject::Volume ? $backup['volumes'] : [0 => $backup['snapshot']];

                        foreach ($targets as $volumeId => $snapshot) {
                            $run = new RestoreRun([
                                'team_id' => $instance->team_id,
                                'destination_id' => $destination->id,
                                'snapshot_id' => $snapshot,
                                'source_service_instance_id' => $instance->id,
                                'target_service_instance_id' => $instance->id,
                                'mode' => RestoreMode::InPlace,
                                'subject' => $backup['subject'],
                                'volume_id' => $volumeId === 0 ? null : $volumeId,
                                'server_replacement_id' => $replacement->id,
                                'actor_id' => $user?->id,
                            ]);
                            $run->status = RestoreRunStatus::WaitingForTarget;
                            $run->save();
                            $runs[] = $run->public_id;
                        }

                        $restoreInfo = ['snapshot' => substr($backup['snapshot'], 0, 8), 'takenAt' => $backup['takenAt']?->toIso8601String()];
                    }
                }

                $services[] = [
                    'instance' => $instance->public_id,
                    'service' => $instance->service->public_id,
                    'name' => "{$instance->service->name} ({$instance->environment->project->name} / {$instance->environment->name})",
                    'kind' => $entry['stateful'] ? 'stateful' : 'stateless',
                    'restore' => $restoreInfo,
                    'restoreRuns' => $runs,
                    'deployment' => null,
                    'status' => 'pending',
                    'message' => $entry['stateful'] && $runs === [] ? ($entry['reason'] ?? 'Not restored: it starts empty.') : null,
                ];
            }

            $replacement->forceFill(['services' => $services])->save();

            if ($recovery) {
                $settings = InstanceSettings::current();
                $state = $settings->recovery_state ?? ['startedAt' => now()->toIso8601String()];
                $settings->forceFill(['recovery_state' => [...$state, 'replacementId' => $replacement->id]])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerReplaced,
                actor: $actor,
                teamId: $old->team_id,
                subjectType: 'server',
                subjectId: $old->public_id,
                meta: [
                    'replacement' => $new->public_id,
                    'run' => $replacement->public_id,
                    'roles' => $roles,
                    'restores' => array_sum(array_map(static fn (array $service): int => count($service['restoreRuns']), $services)),
                    'services' => count($services),
                    'recovery' => $recovery,
                ],
            ));

            event(new NodeStateInvalidated(array_values(array_unique([$new->id, ...$peers]))));

            return $replacement;
        });

        // Stateful services first (restores start as soon as they run).
        $this->advance->handle($replacement->id);

        return $replacement->refresh();
    }
}

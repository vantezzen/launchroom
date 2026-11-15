<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Instance\RecoveryStateData;
use App\Data\Servers\ReplacedServiceData;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Enums\ReplacedServiceKind;
use App\Enums\ReplacedServiceStatus;
use App\Enums\ServerReplacementStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\RestoreRun;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Models\User;
use App\Models\Volume;
use App\Queries\ServerReplacementPlan;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * "Replace server" (docs/14 §5.3; the control-plane case runs from recovery
 * mode, §5.2): a dead server's role is taken over by another approved,
 * online server of the team.
 *
 * In one transaction:
 * 1. volumes pinned to the dead server move to the replacement (their
 *    data is restored from backups, they start empty otherwise) and the dead
 *    server's placements are dropped;
 * 2. the replacement takes over the control-plane flag, and
 *    {@see RetireServer} hands it the dead server's roles and labels, gives
 *    the dead one an empty "decommission" NodeState and revokes it: if it
 *    ever comes back, it only stops what it ran;
 * 3. for each stateful service chosen in $restore, an in-place restore of
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
        private RetireServer $retire,
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
            $new->forceFill(['is_control_plane' => $new->is_control_plane || $old->is_control_plane])->save();
            // Its services run on the replacement from now on: volumes move
            // (restored from backups below, empty otherwise), placements go.
            Volume::query()->where('server_id', $old->id)->update(['server_id' => $new->id]);
            Placement::query()->where('server_id', $old->id)->delete();
            Refusal::orThrow($this->retire->handle($old, $new, $actor));

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
                    $destination = BackupDestination::forService($instance);

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

                $services[] = new ReplacedServiceData(
                    instance: $instance->public_id,
                    service: $instance->service->public_id,
                    name: "{$instance->service->name} ({$instance->environment->project->name} / {$instance->environment->name})",
                    kind: $entry['stateful'] ? ReplacedServiceKind::Stateful : ReplacedServiceKind::Stateless,
                    restore: $restoreInfo,
                    restoreRuns: $runs,
                    deployment: null,
                    status: ReplacedServiceStatus::Pending,
                    message: $entry['stateful'] && $runs === [] ? ($entry['reason'] ?? 'Not restored: it starts empty.') : null,
                );
            }

            $replacement->forceFill(['services' => $services])->save();

            if ($recovery) {
                $settings = InstanceSettings::current();
                $state = $settings->recovery_state ?? new RecoveryStateData(now()->toIso8601String());
                $settings->forceFill(['recovery_state' => new RecoveryStateData($state->startedAt, $state->previousControlPlaneServerId, $replacement->id)])->save();
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
                    'roles' => array_values(array_map(static fn (ServerRole $role): string => $role->value, $new->refresh()->roles->all())),
                    'restores' => array_sum(array_map(static fn (ReplacedServiceData $service): int => count($service->restoreRuns), $services)),
                    'services' => count($services),
                    'recovery' => $recovery,
                ],
            ));

            return $replacement;
        });

        // Stateful services first (restores start as soon as they run).
        $this->advance->handle($replacement->id);

        return $replacement->refresh();
    }
}

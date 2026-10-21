<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Data\Specs\ChangeItem;
use App\Enums\AuditAction;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\Backups\RestoreMode;
use App\Enums\Backups\RestoreRunStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\RestoreRun;
use App\Models\Backups\WalArchive;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Variable;
use App\Models\Volume;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Backups\BackupSubjects;
use App\Services\Backups\DatabaseWorkloads;
use App\Services\Backups\PointInTimeRecovery;
use App\Services\Backups\SnapshotCache;
use App\Services\Backups\SnapshotTags;
use App\Services\Environments\EnvironmentIntentCopier;
use App\Services\Projects\ServiceProvisioner;
use App\Services\Variables\VariableStore;
use App\Support\Changes\SpecDiff;
use App\Support\Slug;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Starts a database or volume restore (docs/14 §5.1).
 *
 * - **new_service** (default, safe): adds `<name>-restored-<date>` to the
 *   source's environment only (databases: same engine and version; services
 *   with volumes: same source, settings, variables and volume mount paths),
 *   deploys it when nothing else is staged there (otherwise it waits,
 *   staged, for the next Deploy), and restores into it once it runs.
 * - **in_place** (destructive): the user types the service's name to
 *   confirm; a safety snapshot is taken first, and the restore starts only
 *   after it succeeded. Volume restores stop the service while the volume is
 *   replaced (its workloads leave the desired state) and start it again after.
 *
 * The rest happens in {@see AdvanceRestores} (scheduler tick).
 */
final readonly class StartRestore
{
    public function __construct(
        private ServiceProvisioner $provisioner,
        private StartDatabaseBackup $backup,
        private StartVolumeBackup $volumeBackup,
        private EnvironmentIntentCopier $copier,
        private VariableStore $variables,
        private CurrentEnvironmentSpec $currentSpec,
        private DeployEnvironment $deploy,
        private SnapshotCache $snapshots,
        private RecordAuditEvent $audit,
        private PointInTimeRecovery $pointInTime,
    ) {}

    /**
     * Restores a Postgres database to a point in time (docs/14 §10.3): the
     * newest base backup finished at or before $at, replayed with the
     * archived WAL up to $at (null: as far as the archive goes). As a new
     * service or in place, with the same confirmation and safety snapshot
     * as any other restore; {@see AdvanceRestores} runs it once the target
     * is held.
     */
    private function pointInTime(ServiceInstance $source, ?CarbonImmutable $at, RestoreMode $mode, ?string $confirmation, User $user, AuditActor $actor): RestoreRun
    {
        $source->loadMissing(['service', 'environment.project', 'volumes']);
        $archive = WalArchive::query()->where('service_instance_id', $source->id)->first();

        if (! $archive instanceof WalArchive || ! $this->pointInTime->supports($source)) {
            throw DomainRuleViolation::on('pointInTime', 'Point-in-time recovery is not set up for this database.');
        }

        $window = $this->pointInTime->window($archive);

        if ($window === null) {
            throw DomainRuleViolation::on('pointInTime', 'No base backup has finished yet, so there is no point in time to restore to.');
        }

        if ($at instanceof CarbonImmutable && ($at->lt($window[0]) || $at->gt(now()->addMinute()))) {
            throw DomainRuleViolation::on('pointInTime', 'Pick a time between '.$window[0]->toDayDateTimeString().' UTC and now.');
        }

        $base = $this->pointInTime->baseFor($source, $at);

        if (! $base instanceof BackupRun || $base->snapshot_id === null) {
            throw DomainRuleViolation::on('pointInTime', 'No base backup was finished by then. Pick a later time.');
        }

        if (RestoreRun::query()->where('source_service_instance_id', $source->id)->whereNotIn('status', [RestoreRunStatus::Succeeded->value, RestoreRunStatus::Failed->value])->exists()) {
            throw DomainRuleViolation::conflict('A restore of this service is already in progress.');
        }

        $destination = $archive->destination()->firstOrFail();
        $deployEnvironment = null;

        if ($mode === RestoreMode::InPlace) {
            if ($confirmation === null || ! hash_equals($source->service->slug, $confirmation)) {
                throw DomainRuleViolation::on('confirmation', "Type {$source->service->slug} to confirm. Restoring into this service replaces its data.");
            }

            if (! DatabaseWorkloads::servingPlacement($source) instanceof Placement) {
                throw DomainRuleViolation::on('service', 'This database isn’t running. Deploy it, or restore as a new service instead.');
            }

            $run = DB::transaction(function () use ($source, $base, $destination, $at, $user, $actor): RestoreRun {
                $safety = $this->backup->handle($source, BackupReason::PreRestore, $user, $actor);
                $run = new RestoreRun([
                    'team_id' => $source->team_id,
                    'destination_id' => $destination->id,
                    'snapshot_id' => $base->snapshot_id,
                    'source_service_instance_id' => $source->id,
                    'target_service_instance_id' => $source->id,
                    'mode' => RestoreMode::InPlace,
                    'subject' => BackupSubject::BaseBackup,
                    'volume_id' => $source->volumes->sortBy('id')->first()?->id,
                    'actor_id' => $user->id,
                    'point_in_time' => $at,
                ]);
                $run->status = RestoreRunStatus::WaitingForSafetyBackup;
                $run->safety_backup_run_id = $safety->id;
                $run->save();

                return $run;
            });
        } else {
            [$run, $deployEnvironment] = $this->asNewService($source, (string) $base->snapshot_id, $destination, $user, null);
            $volume = Volume::query()->where('service_instance_id', $run->target_service_instance_id)->orderBy('id')->first();
            $run->forceFill(['subject' => BackupSubject::BaseBackup, 'volume_id' => $volume?->id, 'point_in_time' => $at])->save();
        }

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::RestoreStarted,
            actor: $actor,
            teamId: $source->team_id,
            subjectType: 'service',
            subjectId: $source->service->public_id,
            meta: ['restore' => $run->public_id, 'mode' => $mode->value, 'snapshot' => substr((string) $base->snapshot_id, 0, 16), 'pointInTime' => $at?->toIso8601ZuluString() ?? 'latest', 'environment' => $source->environment->public_id],
        ));

        if ($deployEnvironment instanceof Environment) {
            try {
                $this->deploy->handle($deployEnvironment, $user, $actor, 'Restore '.$source->service->name.' to '.($at instanceof CarbonImmutable ? $at->toDateTimeString().' UTC' : 'its newest point').' into a new service');
            } catch (DomainRuleViolation) {
                // Stays staged: the restore waits until the next Deploy starts it.
            }
        }

        return $run;
    }

    /**
     * @param  bool  $pointInTime  restore to $at (null: the newest point) from base backup + WAL instead of $snapshotId
     */
    public function handle(ServiceInstance $source, string $snapshotId, RestoreMode $mode, ?string $confirmation, User $user, AuditActor $actor, bool $pointInTime = false, ?CarbonImmutable $at = null): RestoreRun
    {
        if ($pointInTime) {
            return $this->pointInTime($source, $at, $mode, $confirmation, $user, $actor);
        }

        if (preg_match('/^[a-f0-9]{8,64}$/D', $snapshotId) !== 1) {
            throw DomainRuleViolation::on('snapshot', 'Pick a snapshot from the list.');
        }

        $source->loadMissing(['service', 'environment.project', 'volumes']);
        $subject = BackupSubjects::for($source);

        if ($subject === BackupSubject::Database && ! DatabaseWorkloads::source($source) instanceof DatabaseSource) {
            $subject = null;
        }

        if (! $subject instanceof BackupSubject) {
            throw DomainRuleViolation::on('service', 'Only databases and services with volumes can be restored.');
        }

        $database = DatabaseWorkloads::source($source);
        $serving = DatabaseWorkloads::servingPlacement($source);
        $unsupported = $subject === BackupSubject::Database && $database instanceof DatabaseSource && $serving instanceof Placement
            ? DatabaseWorkloads::unsupportedBy($database, $serving->server, 'restore')
            : null;

        if ($unsupported !== null) {
            throw DomainRuleViolation::on('daemon', $unsupported);
        }

        [$snapshotId, $volumeId] = $this->knownSnapshot($source, $snapshotId, $subject);
        $destination = BackupSchedule::query()->where('service_instance_id', $source->id)->first()?->destination()->first()
            ?? BackupDestination::defaultFor($source->team_id);

        if (! $destination instanceof BackupDestination) {
            throw DomainRuleViolation::on('destination', 'This service has no backup destination.');
        }

        if (RestoreRun::query()->where('source_service_instance_id', $source->id)->whereNotIn('status', [RestoreRunStatus::Succeeded->value, RestoreRunStatus::Failed->value])->exists()) {
            throw DomainRuleViolation::conflict('A restore of this service is already in progress.');
        }

        $volume = null;

        if ($subject === BackupSubject::Volume) {
            $volume = $source->volumes->firstWhere('public_id', $volumeId);

            if (! $volume instanceof Volume) {
                throw DomainRuleViolation::on('snapshot', 'The volume this snapshot was taken of no longer belongs to this service.');
            }
        }

        [$run, $deployEnvironment] = $mode === RestoreMode::InPlace
            ? [$this->inPlace($source, $snapshotId, $destination, $confirmation, $user, $actor, $volume), null]
            : $this->asNewService($source, $snapshotId, $destination, $user, $volume);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::RestoreStarted,
            actor: $actor,
            teamId: $source->team_id,
            subjectType: 'service',
            subjectId: $source->service->public_id,
            meta: ['restore' => $run->public_id, 'mode' => $mode->value, 'snapshot' => substr($snapshotId, 0, 16), 'environment' => $source->environment->public_id],
        ));

        if ($deployEnvironment instanceof Environment) {
            try {
                $this->deploy->handle($deployEnvironment, $user, $actor, 'Restore backup '.substr($snapshotId, 0, 8).' into a new service');
            } catch (DomainRuleViolation) {
                // Stays staged: the restore waits until the next Deploy starts it.
            }
        }

        return $run;
    }

    /**
     * The snapshot must be one of this service's: a recorded run or a
     * listing from the repository (filtered by its tags). Resolves short ids
     * and, for volume snapshots, the volume (vol_…) it was taken of.
     *
     * @return array{string, string|null}
     */
    private function knownSnapshot(ServiceInstance $source, string $snapshotId, BackupSubject $subject): array
    {
        $run = BackupRun::query()
            ->where('service_instance_id', $source->id)
            ->where('subject', $subject->value)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->where('snapshot_id', 'like', $snapshotId.'%')
            ->with('volume')
            ->first();

        if ($run instanceof BackupRun && $run->snapshot_id !== null) {
            return [$run->snapshot_id, $run->volume?->public_id];
        }

        foreach ($this->snapshots->forInstance($source)['snapshots'] ?? [] as $snapshot) {
            if (str_starts_with($snapshot['id'], $snapshotId) && SnapshotTags::value($snapshot['tags'], 'kind') === $subject->tag()) {
                return [$snapshot['id'], SnapshotTags::value($snapshot['tags'], 'volume')];
            }
        }

        throw DomainRuleViolation::on('snapshot', 'This snapshot is not a backup of this service. Refresh the snapshot list and pick one from it.');
    }

    private function inPlace(ServiceInstance $source, string $snapshotId, BackupDestination $destination, ?string $confirmation, User $user, AuditActor $actor, ?Volume $volume): RestoreRun
    {
        if ($confirmation === null || ! hash_equals($source->service->slug, $confirmation)) {
            throw DomainRuleViolation::on('confirmation', "Type {$source->service->slug} to confirm. Restoring into this service replaces its data.");
        }

        if (! $volume instanceof Volume && ! DatabaseWorkloads::servingPlacement($source) instanceof Placement) {
            throw DomainRuleViolation::on('service', 'This database isn’t running. Deploy it, or restore as a new service instead.');
        }

        return DB::transaction(function () use ($source, $snapshotId, $destination, $user, $actor, $volume): RestoreRun {
            $safety = $volume instanceof Volume
                ? $this->volumeBackup->handle($source, $volume, BackupReason::PreRestore, $user, $actor)
                : $this->backup->handle($source, BackupReason::PreRestore, $user, $actor);

            $run = new RestoreRun([
                'team_id' => $source->team_id,
                'destination_id' => $destination->id,
                'snapshot_id' => $snapshotId,
                'source_service_instance_id' => $source->id,
                'target_service_instance_id' => $source->id,
                'mode' => RestoreMode::InPlace,
                'subject' => $volume instanceof Volume ? BackupSubject::Volume : BackupSubject::Database,
                'volume_id' => $volume?->id,
                'actor_id' => $user->id,
            ]);
            $run->status = RestoreRunStatus::WaitingForSafetyBackup;
            $run->safety_backup_run_id = $safety->id;
            $run->save();

            return $run;
        });
    }

    /**
     * @return array{RestoreRun, Environment|null} the run and the environment to deploy now (if nothing else is staged there)
     */
    private function asNewService(ServiceInstance $source, string $snapshotId, BackupDestination $destination, User $user, ?Volume $volume): array
    {
        return DB::transaction(function () use ($source, $snapshotId, $destination, $user, $volume): array {
            $environment = Environment::query()->whereKey($source->environment_id)->with('project', 'lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $project = $environment->project;
            $original = $source->service;

            /** @var list<string> $taken */
            $taken = $project->services()->pluck('slug')->all();
            $name = mb_substr($original->name, 0, 40).'-restored-'.now()->format('Y-m-d');
            $slug = Slug::unique(Slug::from($name, 'restored'), [...$taken, ...Slug::RESERVED]);

            $service = Service::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'slug' => $slug,
                'name' => $name,
                'kind' => $original->kind,
                'parent_service_id' => $original->parent_service_id,
                'source' => $original->source,
                'icon' => $original->icon,
            ]);

            // Only in this environment: a restored copy is an inspection
            // target, not a new service for every environment.
            $targetVolume = null;

            if ($volume instanceof Volume) {
                // Same settings, variables and volume mount paths; the volume starts empty.
                $target = $this->provisioner->provision($service, $environment, $source, actor: $user);
                $this->copyVariables($source, $service, $environment, $user);

                foreach ($source->volumes as $sourceVolume) {
                    $this->copier->addVolume($target, $sourceVolume->mount_path);
                }

                $targetVolume = Volume::query()->where('service_instance_id', $target->id)->where('mount_path', $volume->mount_path)->firstOrFail();
            } else {
                $target = $this->provisioner->provision($service, $environment, actor: $user);
            }

            $run = new RestoreRun([
                'team_id' => $source->team_id,
                'destination_id' => $destination->id,
                'snapshot_id' => $snapshotId,
                'source_service_instance_id' => $source->id,
                'target_service_instance_id' => $target->id,
                'mode' => RestoreMode::NewService,
                'subject' => $targetVolume instanceof Volume ? BackupSubject::Volume : BackupSubject::Database,
                'volume_id' => $targetVolume?->id,
                'actor_id' => $user->id,
            ]);
            $run->status = RestoreRunStatus::WaitingForTarget;
            $run->save();

            $changes = SpecDiff::between($environment->lastAppliedRevision?->spec, $this->currentSpec->get($environment));
            $onlyThisService = array_all($changes->items, static fn (ChangeItem $item): bool => $item->serviceId === $service->public_id);

            return [$run, $onlyThisService ? $environment : null];
        });
    }

    /**
     * The original's own variables that apply to this environment, as rows
     * of the copy (connection variables stay with the original).
     */
    private function copyVariables(ServiceInstance $source, Service $copy, Environment $environment, User $user): void
    {
        $environment->loadMissing('project');
        $variables = Variable::query()
            ->where('service_id', $source->service_id)
            ->whereNull('connection_id')
            ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $environment->id))
            ->orderBy('id')
            ->get();

        foreach ($variables as $variable) {
            $this->variables->create(
                $environment->project,
                $copy,
                $variable->key,
                $this->variables->plaintext($environment->project, $variable),
                $variable->type,
                [['environment_id' => $environment->id, 'all_previews' => false, 'branch_pattern' => null]],
                $variable->exposure_override,
                actor: $user,
            );
        }
    }
}

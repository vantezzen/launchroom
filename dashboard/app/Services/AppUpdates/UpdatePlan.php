<?php

declare(strict_types=1);

namespace App\Services\AppUpdates;

use App\Data\Holds\Hold;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Backups\BackupDestination;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Backups\BackupSubjects;
use App\Services\Holds\Holds;
use App\Support\Changes\SpecDiff;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What an update of one service involves and whether it can start now
 * (docs/04 §14.2): the data it backs up first (the databases the service is
 * connected to and its own backed-up volumes) and the reason it can't start
 * yet, in one sentence. The start, the automatic updates and the UI all ask
 * here, so they agree.
 */
final readonly class UpdatePlan
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    /**
     * Which instances are checked and updated: running image services of
     * lasting environments.
     */
    public static function isChecked(ServiceInstance $instance): bool
    {
        $instance->loadMissing(['service', 'environment']);

        return $instance->service->kind === ServiceKind::Image
            && $instance->environment->kind !== EnvironmentKind::Preview
            && $instance->removed_at === null
            && $instance->stopped_at === null
            && $instance->active_deployment_id !== null;
    }

    /**
     * Why an update of $instance can't start right now, or null. $running
     * is the update asking (it doesn't block itself).
     */
    public function blocker(ServiceInstance $instance, ?EnvironmentSpec $current = null, ?AppUpdate $running = null): ?string
    {
        $instance->loadMissing(['service', 'environment.lastAppliedRevision']);
        $name = $instance->service->name;

        if ($instance->removed_at !== null) {
            return "{$name} is staged for removal.";
        }

        if ($instance->stopped_at !== null) {
            return "{$name} is stopped. Start it first.";
        }

        if ($instance->active_deployment_id === null) {
            return "{$name} isn’t live yet. Deploy it first.";
        }

        if (AppUpdate::query()->where('service_instance_id', $instance->id)->open()->when($running instanceof AppUpdate, static fn ($query) => $query->whereKeyNot($running?->id))->exists()) {
            return "An update of {$name} is already running.";
        }

        if (Deployment::query()->where('service_instance_id', $instance->id)->unfinished()->exists()) {
            return "{$name} is deploying. Update it once that finished.";
        }

        $hold = Holds::blocking($instance->id);

        if ($hold instanceof Hold) {
            return ucfirst($hold->reason).'. Update it once '.$hold->ends.' has finished.';
        }

        $others = $this->otherStagedChanges($instance, $current);

        if ($others > 0) {
            return $others === 1
                ? 'Deploy or discard the other staged change first, so the update goes live alone.'
                : "Deploy or discard the {$others} other staged changes first, so the update goes live alone.";
        }

        return null;
    }

    /**
     * Staged changes in the instance's environment besides this service's
     * image (which another environment's update may already have staged).
     */
    public function otherStagedChanges(ServiceInstance $instance, ?EnvironmentSpec $current = null): int
    {
        $instance->loadMissing(['service', 'environment.lastAppliedRevision']);
        $environment = $instance->environment;
        $changes = SpecDiff::between($environment->lastAppliedRevision?->spec, $current ?? $this->currentSpec->get($environment));
        $own = 'services.'.$instance->service->public_id.'.source';

        return count(array_filter($changes->items, static fn (ChangeItem $item): bool => $item->path !== $own));
    }

    /**
     * The service instances whose data is backed up before the update: the
     * databases it is connected to (live in the same environment), then the
     * service itself when it has volumes that are backed up.
     *
     * @return list<ServiceInstance>
     */
    public function backupSubjects(ServiceInstance $instance): array
    {
        $instance->loadMissing(['service', 'volumes']);
        $databases = Connection::query()
            ->where('from_service_id', $instance->service_id)
            ->pluck('to_service_id')
            ->all();

        $subjects = ServiceInstance::query()
            ->where('environment_id', $instance->environment_id)
            ->whereIn('service_id', $databases)
            ->whereNull('removed_at')
            ->whereNotNull('active_deployment_id')
            ->whereHas('service', static fn (Builder $query) => $query->where('kind', ServiceKind::Database->value))
            ->with(['service', 'volumes'])
            ->orderBy('id')
            ->get()
            ->all();

        if (BackupSubjects::backedUpVolumes($instance) !== []) {
            $subjects[] = $instance;
        }

        return array_values($subjects);
    }

    /**
     * "Postgres and 1 volume of listmonk", for the dialog and the progress line.
     *
     * @param  list<ServiceInstance>|null  $subjects  {@see BackupSubjects()}, when the caller has them
     * @return list<string>
     */
    public function describe(ServiceInstance $instance, ?array $subjects = null): array
    {
        $parts = [];

        foreach ($subjects ?? $this->backupSubjects($instance) as $subject) {
            if ($subject->id === $instance->id) {
                $volumes = count(BackupSubjects::backedUpVolumes($subject));
                $parts[] = $volumes === 1 ? "{$subject->service->name}’s volume" : "{$volumes} volumes of {$subject->service->name}";

                continue;
            }

            $parts[] = $subject->service->name;
        }

        return $parts;
    }

    /**
     * Whether backups of $instance's data have somewhere to go.
     */
    public function hasBackupStorage(ServiceInstance $instance): bool
    {
        return BackupDestination::defaultFor($instance->team_id) instanceof BackupDestination;
    }
}

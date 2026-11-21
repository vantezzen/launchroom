<?php

declare(strict_types=1);

namespace App\Queries\AppUpdates;

use App\Data\AppUpdates\AppUpdateRunData;
use App\Data\AppUpdates\ServiceUpdateData;
use App\Data\AppUpdates\UpdateCandidateData;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Models\AppUpdates\AppUpdate;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\Backups\BackupRun;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\AppUpdates\UpdatePlan;
use App\Support\AppUpdates\UpdateChoice;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Links;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Updates of image services for one viewer (docs/04 §14.4): one service
 * instance (panel, settings) or every instance of a team with something to
 * say (the Security page's App updates). Team-scoped by construction.
 */
final readonly class ServiceUpdates
{
    /** A failed or rolled-back update stays visible this long. */
    private const int RECENT_HOURS = 24;

    public function __construct(
        private UpdatePlan $plan,
        private CurrentEnvironmentSpec $currentSpec,
    ) {}

    /**
     * Null for services that never update this way (not an image, a preview).
     */
    public function for(ServiceInstance $instance, User $user, ?EnvironmentSpec $current = null): ?ServiceUpdateData
    {
        $instance->loadMissing(['service', 'environment']);

        if ($instance->service->kind !== ServiceKind::Image || $instance->environment->kind === EnvironmentKind::Preview) {
            return null;
        }

        $instance->loadMissing(['environment.project', 'updateCheck']);

        return $this->data($instance, $user, $current);
    }

    /**
     * Instances of $team with an available update or an update to show,
     * optionally of one project (an id the caller checked is the team's).
     * Updates in flight first, then what stands out (a major, or a version
     * more than a year old), then the rest by project and service.
     *
     * @return list<ServiceUpdateData>
     */
    public function forTeam(Team $team, User $user, ?int $projectId = null): array
    {
        $instances = ServiceInstance::query()
            ->where('team_id', $team->id)
            ->whereNull('removed_at')
            ->whereHas('service', static fn (Builder $query) => $query->where('kind', ServiceKind::Image->value)
                ->when($projectId !== null, static fn (Builder $query) => $query->where('project_id', $projectId)))
            ->whereHas('environment', static fn (Builder $query) => $query->where('kind', '!=', EnvironmentKind::Preview->value))
            ->where(static fn (Builder $query) => $query
                ->whereHas('updateCheck', static fn (Builder $check) => $check->where('status', UpdateCheckStatus::Available->value))
                ->orWhereIn('id', AppUpdate::query()->open()->select('service_instance_id')))
            ->with(['service', 'environment.project', 'updateCheck', 'volumes'])
            ->orderBy('id')
            ->get();

        $specs = [];
        $rows = [];

        foreach ($instances as $instance) {
            $rows[] = $this->data($instance, $user, null, $specs);
        }

        $rank = static fn (ServiceUpdateData $row): array => [
            $row->run?->status->isOpen() ?? false ? 0 : 1,
            $row->standsOut ? 0 : 1,
            $row->projectName,
            $row->serviceName,
            $row->production ? 0 : 1,
            $row->environmentName,
        ];
        usort($rows, static fn (ServiceUpdateData $a, ServiceUpdateData $b): int => $rank($a) <=> $rank($b));

        return $rows;
    }

    /**
     * @param  array<int, EnvironmentSpec>  $specs  per environment id, shared by one listing
     */
    private function data(ServiceInstance $instance, User $user, ?EnvironmentSpec $current, array &$specs = []): ServiceUpdateData
    {
        $check = $instance->updateCheck;
        $environment = $instance->environment;
        $candidates = $check instanceof AppUpdateCheck && $check->candidates->count() > 0 ? $this->fresh($instance, $check) : [];
        $canUpdate = $instance->removed_at === null && Gate::forUser($user)->allows('update', $instance->service) && Gate::forUser($user)->allows('deploy', $environment);
        $blocker = null;

        if ($canUpdate && $candidates !== []) {
            $current ??= $specs[$environment->id] ??= $this->currentSpec->get($environment);
            $blocker = $this->plan->blocker($instance, $current);
        }

        // Before the first check there is nothing to update, so nothing to back up yet either.
        $subjects = $check instanceof AppUpdateCheck ? $this->plan->backupSubjects($instance) : [];
        $hasData = $subjects !== [];
        $storage = $hasData && $this->plan->hasBackupStorage($instance);
        $status = $check->status ?? UpdateCheckStatus::Unknown;

        return new ServiceUpdateData(
            serviceId: $instance->service->public_id,
            serviceName: $instance->service->name,
            logo: ServiceGlyph::logo($instance->service),
            projectId: $environment->project->public_id,
            projectName: $environment->project->name,
            environmentId: $environment->public_id,
            environmentName: $environment->name,
            production: $environment->kind === EnvironmentKind::Production,
            status: $candidates === [] && $status === UpdateCheckStatus::Available ? UpdateCheckStatus::Current : $status,
            reason: $check instanceof AppUpdateCheck ? $check->reason : (UpdatePlan::isChecked($instance) ? null : 'launchroom checks for updates once the service is live.'),
            running: UpdateChoice::runningVersion($check?->image),
            moving: UpdateChoice::isMoving($check?->image),
            standsOut: UpdateChoice::major($candidates) instanceof UpdateCandidateData || ($candidates !== [] && $check->running_published_at?->lessThanOrEqualTo(now()->subYear()) === true),
            runningPublishedAt: $check?->running_published_at?->toIso8601ZuluString(),
            image: $check?->image,
            candidates: $candidates,
            recommended: UpdateChoice::recommended($candidates),
            major: UpdateChoice::major($candidates),
            releaseNotesUrl: $check?->release_notes_url,
            checkedAt: $check?->checked_at?->toIso8601ZuluString(),
            run: $check instanceof AppUpdateCheck ? $this->run($instance) : null,
            checkable: UpdatePlan::isChecked($instance),
            canUpdate: $canUpdate,
            blocker: $blocker,
            backups: $storage ? $this->plan->describe($instance, $subjects) : [],
            keepsData: $hasData,
            backupStorage: $storage,
            watchMinutes: intdiv(config()->integer('launchroom.deployments.probation_seconds'), 60),
            autoUpdate: $instance->auto_update,
            window: $instance->auto_update_window,
            autoBlocker: $hasData && ! $storage && $instance->auto_update === AutoUpdateMode::Off ? 'Set up backup storage first: automatic updates back up the data before they run.' : null,
        );
    }

    /**
     * The check's candidates, minus what runs now (an update since the
     * check put one of them live; the next check catches up).
     *
     * @return list<UpdateCandidateData>
     */
    private function fresh(ServiceInstance $instance, AppUpdateCheck $check): array
    {
        $running = $instance->loadMissing('activeDeployment')->activeDeployment?->image;
        $digest = is_string($running) && str_contains($running, '@') ? substr($running, strrpos($running, '@') + 1) : null;

        /** @var list<UpdateCandidateData> $candidates */
        $candidates = $check->candidates->all();

        return array_values(array_filter($candidates, static fn (UpdateCandidateData $candidate): bool => $candidate->digest === null || $candidate->digest !== $digest));
    }

    private function run(ServiceInstance $instance): ?AppUpdateRunData
    {
        $update = AppUpdate::query()
            ->where('service_instance_id', $instance->id)
            ->where(static fn (Builder $query) => $query
                ->whereIn('status', array_map(static fn (AppUpdateStatus $status): string => $status->value, AppUpdateStatus::open()))
                ->orWhere('finished_at', '>=', now()->subHours(self::RECENT_HOURS)))
            ->with(['deployment', 'actor:id,name'])
            ->latest('id')
            ->first();

        // A finished update stays only while it has something to say.
        if (! $update instanceof AppUpdate || ($update->status === AppUpdateStatus::Succeeded && $update->finished_at?->lessThan(now()->subHour()))) {
            return null;
        }

        $backingUp = $update->status === AppUpdateStatus::BackingUp
            ? BackupRun::query()->whereIn('id', $update->backup_run_ids)->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])->with('serviceInstance.service', 'volume')
                ->get()->map(static fn (BackupRun $run): string => $run->volume !== null ? "{$run->serviceInstance?->service->name}’s volume {$run->volume->mount_path}" : (string) $run->serviceInstance?->service->name)->unique()->values()->all()
            : [];
        $backingUp = array_values(array_filter($backingUp, is_string(...)));

        $deployment = $update->deployment;

        return new AppUpdateRunData(
            id: $update->public_id,
            status: $update->status,
            automatic: $update->automatic,
            kind: $update->kind,
            fromVersion: $update->from_version,
            toVersion: $update->to_version,
            actor: $update->actor?->name,
            deploymentId: $deployment?->public_id,
            deploymentHref: $deployment instanceof Deployment ? Links::deployment($deployment) : null,
            message: $update->message,
            backupNote: $update->backup_note,
            backingUp: $backingUp,
            startedAt: $update->started_at->toIso8601ZuluString(),
            finishedAt: $update->finished_at?->toIso8601ZuluString(),
            watchUntil: $update->status === AppUpdateStatus::Watching ? $deployment?->probation_until?->toIso8601ZuluString() : null,
        );
    }
}

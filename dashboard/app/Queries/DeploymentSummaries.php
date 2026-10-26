<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\DeploymentSummaryData;
use App\Data\Deployments\ServiceStoppedData;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Git\GitHubAvatar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Turns deployments into list rows with a fixed number of queries (no N+1):
 * service and environment names, actor names, domains, the active flag and
 * the viewer's rollback permission.
 */
final class DeploymentSummaries
{
    /**
     * Eager loads every row needs; apply to any deployment query first.
     *
     * @param  Builder<Deployment>  $query
     * @return Builder<Deployment>
     */
    public static function withRelations(Builder $query): Builder
    {
        return $query->with([
            'serviceInstance:id,public_id,service_id,environment_id,active_deployment_id,stopped_at,stopped_by_id',
            'serviceInstance.service:id,public_id,name,slug,project_id',
            'serviceInstance.service.project:id,public_id',
            'environment:id,public_id,name,slug,project_id',
            'rollbackOf:id,public_id',
        ]);
    }

    /**
     * @param  Collection<int, Deployment>  $deployments  loaded through withRelations()
     * @return list<DeploymentSummaryData>
     */
    public function rows(Collection $deployments): array
    {
        if ($deployments->isEmpty()) {
            return [];
        }

        $stoppers = $deployments
            ->map(static fn (Deployment $deployment): ?int => $deployment->serviceInstance->stopped_by_id)
            ->filter();

        /** @var array<int, string> $actors */
        $actors = User::query()
            ->whereIn('id', $deployments->pluck('actor_id')->merge($stoppers)->filter()->unique()->values()->all())
            ->pluck('name', 'id')
            ->all();

        /** @var array<int, list<string>> $domains */
        $domains = Domain::query()
            ->whereIn('service_instance_id', $deployments->pluck('service_instance_id')->unique()->values()->all())
            ->whereNull('redirect_to')
            ->orderByRaw("CASE WHEN kind = 'custom' THEN 0 ELSE 1 END")
            ->orderBy('hostname')
            ->get(['service_instance_id', 'hostname'])
            ->groupBy('service_instance_id')
            ->map(static fn (Collection $rows): array => $rows->map(static fn (Domain $domain): string => 'https://'.$domain->hostname)->values()->all())
            ->all();

        return array_values($deployments->map(fn (Deployment $deployment): DeploymentSummaryData => $this->row(
            $deployment,
            $deployment->actor_id === null ? null : ($actors[$deployment->actor_id] ?? null),
            $domains[$deployment->service_instance_id] ?? [],
            $actors,
        ))->all());
    }

    /**
     * The newest deployments of a service instance and its active one
     * (which may be older than the history window).
     *
     * @return array{active: DeploymentSummaryData|null, rows: list<DeploymentSummaryData>}
     */
    public function forInstance(ServiceInstance $instance, int $limit): array
    {
        $rows = $this->rows(self::withRelations(Deployment::query()->where('service_instance_id', $instance->id))->latest('id')->limit($limit)->get());
        $active = array_values(array_filter($rows, static fn (DeploymentSummaryData $row): bool => $row->isActive))[0] ?? null;

        if (! $active instanceof DeploymentSummaryData && $instance->active_deployment_id !== null) {
            $deployment = self::withRelations(Deployment::query()->whereKey($instance->active_deployment_id))->first();
            $active = $deployment === null ? null : $this->one($deployment);
        }

        return ['active' => $active, 'rows' => $rows];
    }

    /**
     * Deployments of a project's environments, newest first. `status` is a
     * DeploymentStatus value or `in_progress`.
     *
     * @param  list<int>  $environmentIds  the project's environments
     * @return list<DeploymentSummaryData>
     */
    public function forProject(int $teamId, array $environmentIds, ?int $environmentId, ?int $serviceId, ?string $status, int $limit): array
    {
        $query = Deployment::query()
            ->where('team_id', $teamId)
            ->whereIn('environment_id', $environmentIds)
            ->when($environmentId !== null, static fn (Builder $q) => $q->where('environment_id', $environmentId))
            ->when($serviceId !== null, static fn (Builder $q) => $q->whereHas('serviceInstance', static fn (Builder $i) => $i->where('service_id', $serviceId)))
            ->when($status === 'in_progress', static fn (Builder $q) => $q->whereIn('status', DeploymentStatus::values(DeploymentStatus::inFlight())))
            ->when($status !== null && $status !== 'in_progress', static fn (Builder $q) => $q->where('status', $status));

        return $this->rows(self::withRelations($query)->latest('id')->limit($limit)->get());
    }

    public function one(Deployment $deployment): DeploymentSummaryData
    {
        $rows = $this->rows(new Collection([$deployment]));

        return $rows[0];
    }

    /**
     * @param  list<string>  $urls
     * @param  array<int, string>  $names  member names by id
     */
    private function row(Deployment $deployment, ?string $actorName, array $urls, array $names): DeploymentSummaryData
    {
        $instance = $deployment->serviceInstance;
        $active = $instance->active_deployment_id === $deployment->id;
        $stopped = $active && $instance->stopped_at !== null
            ? new ServiceStoppedData($instance->stopped_at, $instance->stopped_by_id === null ? null : ($names[$instance->stopped_by_id] ?? null))
            : null;
        $service = $instance->service;
        $started = $deployment->started_at ?? $deployment->queued_at;
        $duration = $deployment->finished_at === null ? null : (int) max(0, $started->diffInSeconds($deployment->finished_at));

        return new DeploymentSummaryData(
            id: $deployment->public_id,
            status: $deployment->status,
            statusLabel: $deployment->status->label(),
            trigger: $deployment->trigger,
            projectId: $service->project->public_id,
            serviceId: $service->public_id,
            serviceName: $service->name,
            serviceSlug: $service->slug,
            environmentId: $deployment->environment->public_id,
            environmentName: $deployment->environment->name,
            gitRef: $deployment->git_ref,
            commitSha: $deployment->commit_sha,
            commitMessage: $deployment->commit_message,
            commitAuthor: $deployment->commit_author,
            actorName: $actorName,
            failureClass: $deployment->failure_class,
            failureSummary: $this->firstLine($deployment->failure_message),
            isActive: $active,
            urls: $urls,
            queuedAt: $deployment->queued_at,
            startedAt: $deployment->started_at,
            finishedAt: $deployment->finished_at,
            durationSeconds: $duration,
            rollbackOfId: $deployment->rollbackOf?->public_id,
            canRollback: $instance->stopped_at === null && Gate::allows('rollback', $deployment),
            commitAuthorAvatarUrl: GitHubAvatar::fromAvatarUrl($deployment->commit_author_avatar_url),
            stopped: $stopped,
        );
    }

    private function firstLine(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            if (trim($line) !== '') {
                return mb_substr(trim($line), 0, 300);
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\BuildHistoryEntryData;
use App\Data\Deployments\DeploymentStatsData;
use App\Data\Deployments\ServiceDeploymentStatsData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Derived deployment statistics (direction.md §8.3): the deployments header
 * strip, per-service median durations for the duration bars, and the build
 * history of a service instance. Everything comes from `deployments` and
 * `deployment_steps`; nothing is stored.
 *
 * - "Successful": went live (`active`, or `superseded` after its health step
 *   succeeded). Duration = finished − started (queued when it never started).
 * - Counts skip deployments that never ran: cancelled, and superseded
 *   before they started.
 */
final class DeploymentStats
{
    public const int MEDIAN_DAYS = 30;

    public const int WEEK_DAYS = 7;

    public const int HISTORY = 30;

    /**
     * @param  list<int>  $environmentIds  scope (already authorized and team-scoped by the caller)
     * @param  int|null  $serviceId  narrow to one service (all its environments in scope)
     */
    public function summary(int $teamId, array $environmentIds, ?int $serviceId = null): DeploymentStatsData
    {
        if ($environmentIds === []) {
            return new DeploymentStatsData(0, 0, null, []);
        }

        $scope = static function (Builder $query) use ($teamId, $environmentIds, $serviceId): void {
            $query->where('deployments.team_id', $teamId)->whereIn('deployments.environment_id', $environmentIds);

            if ($serviceId !== null) {
                $query->where('service_instances.service_id', $serviceId);
            }
        };

        $duration = 'extract(epoch from (deployments.finished_at - coalesce(deployments.started_at, deployments.queued_at)))';

        $successful = DB::table('deployments')
            ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
            ->tap($scope)
            ->where('deployments.queued_at', '>=', CarbonImmutable::now()->subDays(self::MEDIAN_DAYS))
            ->whereNotNull('deployments.finished_at')
            ->where(static fn (Builder $query) => $query
                ->where('deployments.status', DeploymentStatus::Active->value)
                ->orWhere(static fn (Builder $q) => $q
                    ->where('deployments.status', DeploymentStatus::Superseded->value)
                    ->whereExists(static fn (Builder $step) => $step->from('deployment_steps')
                        ->whereColumn('deployment_steps.deployment_id', 'deployments.id')
                        ->where('deployment_steps.name', DeploymentStepName::Health->value)
                        ->where('deployment_steps.status', DeploymentStepStatus::Succeeded->value))));

        $overall = (clone $successful)->selectRaw("percentile_cont(0.5) within group (order by {$duration}) as median")->value('median');

        $perInstance = (clone $successful)
            ->groupBy('deployments.service_instance_id')
            ->selectRaw("deployments.service_instance_id as instance, percentile_cont(0.5) within group (order by {$duration}) as median")
            ->pluck('median', 'instance')
            ->all();

        $week = DB::table('deployments')
            ->join('service_instances', 'service_instances.id', '=', 'deployments.service_instance_id')
            ->tap($scope)
            ->where('deployments.queued_at', '>=', CarbonImmutable::now()->subDays(self::WEEK_DAYS))
            ->where('deployments.status', '!=', DeploymentStatus::Cancelled->value)
            ->where(static fn (Builder $query) => $query->where('deployments.status', '!=', DeploymentStatus::Superseded->value)->orWhereNotNull('deployments.started_at'))
            ->groupBy('deployments.service_instance_id')
            ->selectRaw('deployments.service_instance_id as instance, count(*) as total, count(*) filter (where deployments.status = ?) as failed', [DeploymentStatus::Failed->value])
            ->get();

        $counts = [];

        foreach ($week as $row) {
            /** @var object{instance: int|string, total: int|string, failed: int|string} $row */
            $counts[(int) $row->instance] = [(int) $row->total, (int) $row->failed];
        }

        $instanceIds = array_values(array_unique([...array_map(intval(...), array_keys($perInstance)), ...array_keys($counts)]));
        $instances = ServiceInstance::query()
            ->whereIn('id', $instanceIds)
            ->with(['service:id,public_id', 'environment:id,public_id'])
            ->orderBy('id')
            ->get();

        $services = [];

        foreach ($instances as $instance) {
            [$total, $failed] = $counts[$instance->id] ?? [0, 0];
            $services[] = new ServiceDeploymentStatsData(
                serviceId: $instance->service->public_id,
                environmentId: $instance->environment->public_id,
                medianDurationSeconds: $this->seconds($perInstance[$instance->id] ?? null),
                weekTotal: $total,
                weekFailed: $failed,
            );
        }

        return new DeploymentStatsData(
            weekTotal: array_sum(array_column($counts, 0)),
            weekFailed: array_sum(array_column($counts, 1)),
            medianDurationSeconds: $this->seconds($overall),
            services: $services,
        );
    }

    /**
     * The last 30 finished deployments of a service instance, oldest first.
     *
     * @return list<BuildHistoryEntryData>
     */
    public function buildHistory(ServiceInstance $instance, int $limit = self::HISTORY): array
    {
        $deployments = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->whereNotNull('finished_at')
            ->where('status', '!=', DeploymentStatus::Cancelled->value)
            ->where(static fn (\Illuminate\Contracts\Database\Query\Builder $query) => $query->where('status', '!=', DeploymentStatus::Superseded->value)->orWhereNotNull('started_at'))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'public_id', 'status', 'plan', 'queued_at', 'started_at', 'finished_at']);

        $builds = DeploymentStep::query()
            ->whereIn('deployment_id', $deployments->modelKeys())
            ->where('name', DeploymentStepName::Build->value)
            ->where('status', '!=', DeploymentStepStatus::Skipped->value)
            ->get(['deployment_id', 'started_at', 'finished_at'])
            ->keyBy('deployment_id');

        $out = [];

        foreach ($deployments->reverse() as $deployment) {
            $finished = $deployment->finished_at;

            if ($finished === null) {
                continue;
            }

            $durationMs = $deployment->plan['durationMs'] ?? null;
            /** @var DeploymentStep|null $step */
            $step = $builds->get($deployment->id);

            if (! is_int($durationMs) && $step?->started_at !== null && $step->finished_at !== null) {
                $durationMs = (int) $step->started_at->diffInMilliseconds($step->finished_at, true);
            }

            $out[] = new BuildHistoryEntryData(
                id: $deployment->public_id,
                status: $deployment->status,
                buildDurationMs: is_int($durationMs) ? max(0, $durationMs) : null,
                durationSeconds: (int) max(0, ($deployment->started_at ?? $deployment->queued_at)->diffInSeconds($finished)),
                finishedAt: $finished,
            );
        }

        return $out;
    }

    private function seconds(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Metrics\DeployMarkerData;
use App\Enums\DeploymentStatus;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The deploy markers of every time chart (docs/15 §2, design-system §9):
 * deployments that ran in a window (live, superseded, failed or still
 * rolling out; queued and cancelled ones never ran), with
 * the service, the short SHA and a link to the deployment. Metrics, Logs,
 * Analytics and the Security firewall chart all read them here, so a
 * deploy looks and links the same on each.
 *
 * Callers pass service instance ids (or a deployments filter) they
 * resolved inside the team.
 */
final class DeployMarkers
{
    public const int LIMIT = 200;

    /**
     * @param  list<int>  $instanceIds
     * @return list<DeployMarkerData>
     */
    public static function forInstances(array $instanceIds, CarbonInterface|string $from, CarbonInterface|string|null $to = null, int $limit = self::LIMIT): array
    {
        if ($instanceIds === []) {
            return [];
        }

        return self::query(static fn (Builder $q) => $q->whereIn('d.service_instance_id', $instanceIds), $from, $to, $limit);
    }

    /**
     * Markers of the deployments `$filter` keeps (a closure on `deployments as d`).
     *
     * @param  \Closure(Builder): mixed  $filter
     * @return list<DeployMarkerData>
     */
    public static function query(\Closure $filter, CarbonInterface|string $from, CarbonInterface|string|null $to = null, int $limit = self::LIMIT): array
    {
        $from = $from instanceof CarbonInterface ? $from->toIso8601String() : $from;
        $to = $to instanceof CarbonInterface ? $to->toIso8601String() : $to;

        $q = DB::table('deployments as d')
            ->join('service_instances as si', 'si.id', '=', 'd.service_instance_id')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->join('environments as e', 'e.id', '=', 'd.environment_id')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->where($filter)
            ->whereNotIn('d.status', [DeploymentStatus::Queued->value, DeploymentStatus::Cancelled->value])
            ->whereRaw('coalesce(d.finished_at, d.started_at, d.queued_at) >= ?', [$from]);

        if ($to !== null) {
            $q->whereRaw('coalesce(d.finished_at, d.started_at, d.queued_at) < ?', [$to]);
        }

        $rows = $q->orderByRaw('coalesce(d.finished_at, d.started_at, d.queued_at) DESC')
            ->limit($limit)
            ->get(['d.public_id', 's.public_id as service_public_id', 's.name as service_name', 'd.commit_sha', 'd.status', 'd.queued_at', 'd.started_at', 'd.finished_at', 'e.public_id as environment_public_id', 'p.public_id as project_public_id'])
            ->all();

        return array_values(array_reverse(array_map(static fn (object $d): DeployMarkerData => new DeployMarkerData(
            deploymentId: Cast::string($d->public_id ?? null),
            serviceId: Cast::string($d->service_public_id ?? null),
            at: CarbonImmutable::parse(Cast::string($d->finished_at ?? $d->started_at ?? $d->queued_at ?? null))->utc()->toIso8601String(),
            status: Cast::string($d->status ?? null),
            serviceName: Cast::string($d->service_name ?? null),
            sha: is_string($d->commit_sha ?? null) ? substr($d->commit_sha, 0, 7) : null,
            href: route('deployments.show', [
                'project' => Cast::string($d->project_public_id ?? null),
                'environment' => Cast::string($d->environment_public_id ?? null),
                'deployment' => Cast::string($d->public_id ?? null),
            ], false),
        ), $rows)));
    }
}

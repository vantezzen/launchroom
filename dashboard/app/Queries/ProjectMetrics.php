<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Metrics\DeployMarkerData;
use App\Data\Metrics\EnvironmentTotalsData;
use App\Data\Metrics\ProjectMetricsData;
use App\Data\Metrics\ReplicaUsageSeriesData;
use App\Data\Metrics\ServiceUsageSeriesData;
use App\Enums\MetricRange;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use App\Support\Metrics\Cast;
use App\Support\Metrics\MetricWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Usage metrics of one environment's services (docs/design/direction.md §8.3):
 * canvas node footers (every service) and the service panel's Metrics tab
 * (one service with per-replica series), with deploy markers. Callers
 * authorize and resolve the environment/service inside the project.
 */
final readonly class ProjectMetrics
{
    public function __construct(
        private RequestMetrics $requests = new RequestMetrics,
    ) {}

    private const int MAX_DEPLOY_MARKERS = 200;

    /** Group keys of series(): literal SQL only. */
    private const array GROUP_BY = [
        'service_instance_id' => 'service_instance_id as k, at, sum(cpu_cores) as cpu, sum(mem_mb) as mem',
        'replica' => 'replica as k, at, sum(cpu_cores) as cpu, sum(mem_mb) as mem',
    ];

    public function handle(Project $project, Environment $environment, ?ServiceInstance $only, MetricRange $range, ?CarbonImmutable $now = null): ProjectMetricsData
    {
        $window = new MetricWindow($range, $now ?? CarbonImmutable::now());
        $instanceIds = $only instanceof ServiceInstance
            ? [$only->id]
            : array_values(array_map(Cast::id(...), $environment->serviceInstances()->whereNull('removed_at')->pluck('id')->all()));

        $series = $this->series($instanceIds, $window, 'service_instance_id');
        $replicas = $only instanceof ServiceInstance ? $this->series($instanceIds, $window, 'replica') : [];
        $current = $this->current($instanceIds, $window->now);
        $filter = ['instances' => $instanceIds];
        $requestBuckets = $this->requests->buckets($filter, $window, true);
        $requestTotals = $this->requests->totals($filter, $window, true);
        $requestPrevious = $this->requests->totals($filter, $window, true, previous: true);
        $all = [];

        foreach ([...array_values($series), ...array_values($replicas)] as $s) {
            $all[] = $s['cpu'];
            $all[] = $s['mem'];
        }

        foreach ($requestBuckets as $buckets) {
            $all[] = RequestMetrics::rates($buckets, $window);
        }

        $count = $window->visibleCount($all);
        $replicaRows = [];

        foreach ($replicas as $replica => $s) {
            $replicaRows[] = new ReplicaUsageSeriesData($replica, MetricWindow::finish($s['cpu'], $count), MetricWindow::finish($s['mem'], $count));
        }

        $services = array_map(static fn (array $si): ServiceUsageSeriesData => new ServiceUsageSeriesData(
            $si['service_public_id'],
            $si['service_name'],
            $si['environment_public_id'],
            $si['environment_name'],
            $si['project_public_id'],
            $si['project_name'],
            $si['color_slot'],
            MetricWindow::finish($series[$si['id']]['cpu'] ?? [], $count),
            MetricWindow::finish($series[$si['id']]['mem'] ?? [], $count),
            $current[$si['id']]['cpu'] ?? null,
            $current[$si['id']]['mem'] ?? null,
            $replicaRows,
            RequestMetrics::data($requestBuckets[$si['id']] ?? [], $requestTotals[$si['id']] ?? null, $requestPrevious[$si['id']] ?? null, $window, $count),
        ), ServerMetrics::instances($instanceIds));

        return new ProjectMetricsData(
            $project->public_id,
            $environment->public_id,
            $range,
            $window->bucket,
            $window->timestamps($count),
            $services,
            self::deploys($instanceIds, $window),
            $this->totals($instanceIds, $series, $requestBuckets, $requestTotals, $requestPrevious, $window, $count),
        );
    }

    /**
     * The environment as a whole: services summed per bucket.
     *
     * @param  list<int>  $instanceIds
     * @param  array<int, array{cpu: array<int, float|null>, mem: array<int, float|null>}>  $series
     * @param  array<int, array<int, array{requests: int, s2: int, s3: int, s4: int, s5: int, in: int, out: int, latency: list<int>|null}>>  $requestBuckets
     * @param  array<int, array{requests: int, s2: int, s3: int, s4: int, s5: int, in: int, out: int, latency: list<int>|null}>  $requestTotals
     * @param  array<int, array{requests: int, s2: int, s3: int, s4: int, s5: int, in: int, out: int, latency: list<int>|null}>  $requestPrevious
     */
    private function totals(array $instanceIds, array $series, array $requestBuckets, array $requestTotals, array $requestPrevious, MetricWindow $window, int $count): EnvironmentTotalsData
    {
        $cpu = $mem = [];

        for ($i = 0; $i < $window->count; $i++) {
            $c = $m = null;

            foreach ($series as $s) {
                if (($s['cpu'][$i] ?? null) !== null) {
                    $c = ($c ?? 0.0) + $s['cpu'][$i];
                }

                if (($s['mem'][$i] ?? null) !== null) {
                    $m = ($m ?? 0.0) + $s['mem'][$i];
                }
            }

            $cpu[$i] = Cast::round($c, 3);
            $mem[$i] = Cast::round($m, 0);
        }

        $buckets = [];

        foreach ($requestBuckets as $perInstance) {
            foreach ($perInstance as $i => $agg) {
                $buckets[$i] = RequestMetrics::sum([$buckets[$i] ?? null, $agg]);
            }
        }

        $buckets = array_filter($buckets);
        $average = static function (array $values): ?float {
            $present = [];

            foreach ($values as $v) {
                if (is_float($v) || is_int($v)) {
                    $present[] = (float) $v;
                }
            }

            return $present === [] ? null : array_sum($present) / count($present);
        };
        $previous = $this->previousUsage($instanceIds, $window);

        return new EnvironmentTotalsData(
            RequestMetrics::data($buckets, RequestMetrics::sum(array_values($requestTotals)), RequestMetrics::sum(array_values($requestPrevious)), $window, $count),
            MetricWindow::finish($cpu, $count),
            MetricWindow::finish($mem, $count),
            Cast::round($average($cpu), 3),
            Cast::round($average($mem), 0),
            $previous['cpu'],
            $previous['mem'],
        );
    }

    /**
     * Average summed CPU and memory over the same length of time just before the range.
     *
     * @param  list<int>  $instanceIds
     * @return array{cpu: float|null, mem: float|null}
     */
    private function previousUsage(array $instanceIds, MetricWindow $window): array
    {
        if ($instanceIds === []) {
            return ['cpu' => null, 'mem' => null];
        }

        $from = CarbonImmutable::createFromTimestampUTC($window->start);
        $row = DB::query()
            ->fromSub(
                DB::table('workload_metric_samples')
                    ->selectRaw('at, sum(cpu_cores) as cpu, sum(mem_mb) as mem')
                    ->whereIn('service_instance_id', $instanceIds)
                    ->where('resolution', $window->resolution())
                    ->where('at', '>=', $from->subSeconds($window->count * $window->bucket)->toIso8601String())
                    ->where('at', '<', $from->toIso8601String())
                    ->groupBy('at'),
                't',
            )
            ->selectRaw('avg(cpu) as cpu, avg(mem) as mem')
            ->first();

        return [
            'cpu' => Cast::round(Cast::float($row->cpu ?? null), 3),
            'mem' => Cast::round(Cast::float($row->mem ?? null), 0),
        ];
    }

    /**
     * CPU and memory summed over workloads per stored minute, averaged per bucket, keyed by $by.
     *
     * @param  list<int>  $instanceIds
     * @param  'service_instance_id'|'replica'  $by
     * @return array<int, array{cpu: array<int, float|null>, mem: array<int, float|null>}>
     */
    private function series(array $instanceIds, MetricWindow $window, string $by): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $rows = DB::query()
            ->fromSub(
                DB::table('workload_metric_samples')
                    ->selectRaw(self::GROUP_BY[$by])
                    ->whereIn('service_instance_id', $instanceIds)
                    ->where('resolution', $window->resolution())
                    ->where('at', '>=', $window->from())
                    ->groupBy($by, 'at'),
                't',
            )
            ->selectRaw('k, '.MetricWindow::BUCKET_SQL.', avg(cpu) as cpu, avg(mem) as mem', $window->bucketBinding())
            ->groupBy('k', 'b')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $i = $window->index($row->b ?? null);

            if ($i === null) {
                continue;
            }

            $k = Cast::id($row->k ?? null);
            $out[$k] ??= ['cpu' => $window->empty(), 'mem' => $window->empty()];
            $out[$k]['cpu'][$i] = Cast::round(Cast::float($row->cpu ?? null), 3);
            $out[$k]['mem'][$i] = Cast::round(Cast::float($row->mem ?? null), 0);
        }

        ksort($out);

        return $out;
    }

    /**
     * Newest minute per service instance (≤ 5 minutes old), summed over its workloads.
     *
     * @param  list<int>  $instanceIds
     * @return array<int, array{cpu: float|null, mem: int|null}>
     */
    private function current(array $instanceIds, CarbonImmutable $now): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $rows = DB::table('workload_metric_samples as w')
            ->whereIn('w.service_instance_id', $instanceIds)
            ->where('w.resolution', 1)
            ->whereRaw('w.at = (SELECT max(x.at) FROM workload_metric_samples x WHERE x.service_instance_id = w.service_instance_id AND x.resolution = 1 AND x.at >= ?)', [$now->subMinutes(ServerMetrics::CURRENT_WITHIN_MINUTES)->toIso8601String()])
            ->groupBy('w.service_instance_id')
            ->selectRaw('w.service_instance_id, sum(w.cpu_cores) as cpu, sum(w.mem_mb) as mem')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[Cast::id($row->service_instance_id ?? null)] = ['cpu' => Cast::round(Cast::float($row->cpu ?? null), 3), 'mem' => Cast::int($row->mem ?? null)];
        }

        return $out;
    }

    /**
     * The newest deploys of these services in the range, oldest first.
     *
     * @param  list<int>  $instanceIds
     * @return list<DeployMarkerData>
     */
    public static function deploys(array $instanceIds, MetricWindow $window): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $rows = DB::table('deployments as d')
            ->join('service_instances as si', 'si.id', '=', 'd.service_instance_id')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->whereIn('d.service_instance_id', $instanceIds)
            ->whereNotNull('d.started_at')
            ->whereRaw('coalesce(d.finished_at, d.started_at) >= ?', [$window->from()])
            ->orderByRaw('coalesce(d.finished_at, d.started_at) DESC')
            ->limit(self::MAX_DEPLOY_MARKERS)
            ->get(['d.public_id', 's.public_id as service_public_id', 's.name as service_name', 'd.commit_sha', 'd.status', 'd.started_at', 'd.finished_at'])
            ->all();

        return array_values(array_reverse(array_map(static fn (object $d): DeployMarkerData => new DeployMarkerData(
            Cast::string($d->public_id ?? null),
            Cast::string($d->service_public_id ?? null),
            CarbonImmutable::parse(Cast::string($d->finished_at ?? $d->started_at ?? null))->utc()->toIso8601String(),
            Cast::string($d->status ?? null),
            Cast::string($d->service_name ?? null),
            is_string($d->commit_sha ?? null) ? substr($d->commit_sha, 0, 7) : null,
        ), $rows)));
    }
}

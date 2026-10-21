<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Metrics\FleetCapacityData;
use App\Data\Metrics\FleetMetricsData;
use App\Data\Metrics\FleetTotalsData;
use App\Data\Metrics\HostSeriesData;
use App\Data\Metrics\HostUsageNowData;
use App\Data\Metrics\ProjectUsageData;
use App\Data\Metrics\ServerMetricsData;
use App\Data\Metrics\ServerUsageData;
use App\Data\Metrics\ServiceUsageSeriesData;
use App\Data\Servers\ServerSpecsData;
use App\Enums\MetricRange;
use App\Models\Server;
use App\Support\Metrics\Cast;
use App\Support\Metrics\MetricWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Usage metrics of servers (docs/design/direction.md §8.3): the fleet's card
 * sparklines and "used by" meters, and one server's tiles and "what runs
 * here". Callers authorize; this only reads the given servers.
 */
final readonly class ServerMetrics
{
    /** "Used by" shows this many projects in colour; the rest fold into "Other". */
    public const int TOP_PROJECTS = 4;

    /** A server whose newest minute is older than this has no current values. */
    public const int CURRENT_WITHIN_MINUTES = 5;

    private const array HOST_KEYS = ['cpu_pct', 'mem_used_mb', 'mem_total_mb', 'mem_pct', 'disk_used_mb', 'disk_total_mb', 'disk_pct', 'net_rx_bps', 'net_tx_bps', 'load1'];

    /**
     * @param  list<Server>  $servers
     */
    public function fleet(array $servers, MetricRange $range, ?CarbonImmutable $now = null): FleetMetricsData
    {
        $window = new MetricWindow($range, $now ?? CarbonImmutable::now());
        $ids = array_map(static fn (Server $server): int => $server->id, $servers);
        $series = $this->hostSeries($ids, $window);
        $current = $this->current($ids, $window->now);
        $usage = $this->currentWorkloads($ids, $window->now);
        $all = [];

        foreach ($series as $s) {
            $all[] = $s['cpu_pct'];
            $all[] = $s['mem_pct'];
        }

        $count = $window->visibleCount($all);

        return new FleetMetricsData(
            $range,
            $window->bucket,
            $window->timestamps($count),
            array_map(fn (Server $server): ServerUsageData => new ServerUsageData(
                $server->public_id,
                MetricWindow::finish($series[$server->id]['cpu_pct'] ?? [], $count),
                MetricWindow::finish($series[$server->id]['mem_pct'] ?? [], $count),
                $current[$server->id] ?? null,
                $this->usedBy($usage[$server->id] ?? [], ($current[$server->id] ?? null)?->memTotalMb),
            ), $servers),
            $this->totals($servers, $series, $current, $count),
        );
    }

    /**
     * The fleet as one machine: per bucket, the share of the reporting
     * servers' capacity in use; CPU weighted by cores (1 when unknown).
     *
     * @param  list<Server>  $servers
     * @param  array<int, array<string, array<int, float|null>>>  $series
     * @param  array<int, HostUsageNowData>  $current
     */
    private function totals(array $servers, array $series, array $current, int $count): FleetTotalsData
    {
        $cores = [];

        foreach ($servers as $server) {
            $cores[$server->id] = max(1, ServerSpecsData::fromServer($server)->cpus ?? 1);
        }

        $cpu = $mem = $disk = array_fill(0, $count, null);

        for ($i = 0; $i < $count; $i++) {
            $cpuUsed = $cpuTotal = $memUsed = $memTotal = $diskUsed = $diskTotal = 0.0;

            foreach ($series as $id => $s) {
                $c = $cores[$id] ?? 1;

                if (($s['cpu_pct'][$i] ?? null) !== null) {
                    $cpuUsed += $s['cpu_pct'][$i] * $c;
                    $cpuTotal += $c;
                }

                if (($s['mem_used_mb'][$i] ?? null) !== null && ($s['mem_total_mb'][$i] ?? 0) > 0) {
                    $memUsed += $s['mem_used_mb'][$i];
                    $memTotal += $s['mem_total_mb'][$i];
                }

                if (($s['disk_used_mb'][$i] ?? null) !== null && ($s['disk_total_mb'][$i] ?? 0) > 0) {
                    $diskUsed += $s['disk_used_mb'][$i];
                    $diskTotal += $s['disk_total_mb'][$i];
                }
            }

            $cpu[$i] = $cpuTotal > 0 ? round($cpuUsed / $cpuTotal, 1) : null;
            $mem[$i] = $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : null;
            $disk[$i] = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null;
        }

        $now = null;

        if ($current !== []) {
            $cpuCores = $memUsedNow = $memTotalNow = $diskUsedNow = $diskTotalNow = 0;
            $cpuUsedNow = 0.0;
            $cpuCounted = 0;

            foreach ($current as $id => $usage) {
                $c = $cores[$id] ?? 1;
                $cpuCores += $c;

                if ($usage->cpuPct !== null) {
                    $cpuUsedNow += $usage->cpuPct / 100 * $c;
                    $cpuCounted += $c;
                }

                if ($usage->memUsedMb !== null && $usage->memTotalMb !== null && $usage->memTotalMb > 0) {
                    $memUsedNow += $usage->memUsedMb;
                    $memTotalNow += $usage->memTotalMb;
                }

                if ($usage->diskUsedMb !== null && $usage->diskTotalMb !== null && $usage->diskTotalMb > 0) {
                    $diskUsedNow += $usage->diskUsedMb;
                    $diskTotalNow += $usage->diskTotalMb;
                }
            }

            $now = new FleetCapacityData(
                count($current),
                $cpuCores,
                round($cpuUsedNow, 2),
                $cpuCounted > 0 ? round($cpuUsedNow / $cpuCounted * 100, 1) : null,
                $memUsedNow,
                $memTotalNow,
                $memTotalNow > 0 ? round($memUsedNow / $memTotalNow * 100, 1) : null,
                $diskUsedNow,
                $diskTotalNow,
                $diskTotalNow > 0 ? round($diskUsedNow / $diskTotalNow * 100, 1) : null,
            );
        }

        return new FleetTotalsData(
            MetricWindow::finish($cpu, $count),
            MetricWindow::finish($mem, $count),
            MetricWindow::finish($disk, $count),
            $now,
        );
    }

    public function server(Server $server, MetricRange $range, ?CarbonImmutable $now = null): ServerMetricsData
    {
        $window = new MetricWindow($range, $now ?? CarbonImmutable::now());
        $host = $this->hostSeries([$server->id], $window)[$server->id] ?? [];
        $current = $this->current([$server->id], $window->now)[$server->id] ?? null;
        $services = $this->serviceSeries($server, $window);
        $all = array_values($host);

        foreach ($services as $s) {
            $all[] = $s['cpu'];
            $all[] = $s['mem'];
        }

        // Public traffic this server served as an edge (docs/15 §2.4).
        $requests = new RequestMetrics;
        $traffic = $requests->buckets(['server' => $server->id], $window, false)[0] ?? [];

        if ($traffic !== []) {
            $all[] = RequestMetrics::rates($traffic, $window);
        }

        $count = $window->visibleCount($all);
        $usage = $this->currentWorkloads([$server->id], $window->now)[$server->id] ?? [];
        $f = static fn (string $key): array => MetricWindow::finish($host[$key] ?? [], $count);

        $rows = array_map(static fn (array $s): ServiceUsageSeriesData => new ServiceUsageSeriesData(
            $s['service_public_id'], $s['service_name'], $s['environment_public_id'], $s['environment_name'],
            $s['project_public_id'], $s['project_name'], $s['color_slot'],
            MetricWindow::finish($s['cpu'], $count), MetricWindow::finish($s['mem'], $count),
            $s['current_cpu'], $s['current_mem'],
        ), $services);
        usort($rows, static fn (ServiceUsageSeriesData $a, ServiceUsageSeriesData $b): int => ($b->currentMemMb ?? -1) <=> ($a->currentMemMb ?? -1) ?: strcmp($a->serviceName, $b->serviceName));

        return new ServerMetricsData(
            $server->public_id,
            $range,
            $window->bucket,
            $window->timestamps($count),
            new HostSeriesData($f('cpu_pct'), $f('mem_used_mb'), $f('mem_pct'), $f('disk_used_mb'), $f('disk_pct'), $f('net_rx_bps'), $f('net_tx_bps'), $f('load1')),
            $current,
            $this->usedBy($usage, $current?->memTotalMb),
            $rows,
            ProjectMetrics::deploys(array_map(static fn (array $s): int => $s['id'], $services), $window),
            RequestMetrics::data(
                $traffic,
                $requests->totals(['server' => $server->id], $window, false)[0] ?? null,
                $requests->totals(['server' => $server->id], $window, false, previous: true)[0] ?? null,
                $window,
                $count,
            ),
        );
    }

    /**
     * Service instance names for metric rows.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, service_public_id: string, service_name: string, environment_public_id: string, environment_name: string, project_public_id: string, project_name: string, color_slot: int}>
     */
    public static function instances(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('service_instances as si')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->whereIn('si.id', $ids)
            ->orderBy('s.name')
            ->get(['si.id', 's.public_id as service_public_id', 's.name as service_name', 'e.public_id as environment_public_id', 'e.name as environment_name', 'p.public_id as project_public_id', 'p.name as project_name', 'p.color_slot'])
            ->map(static fn (object $row): array => [
                'id' => Cast::id($row->id ?? null),
                'service_public_id' => Cast::string($row->service_public_id ?? null),
                'service_name' => Cast::string($row->service_name ?? null),
                'environment_public_id' => Cast::string($row->environment_public_id ?? null),
                'environment_name' => Cast::string($row->environment_name ?? null),
                'project_public_id' => Cast::string($row->project_public_id ?? null),
                'project_name' => Cast::string($row->project_name ?? null),
                'color_slot' => Cast::id($row->color_slot ?? null),
            ])
            ->all();

        return array_values($rows);
    }

    /**
     * @param  list<int>  $serverIds
     * @return array<int, array<string, array<int, float|null>>> server id → metric → values per bucket
     */
    private function hostSeries(array $serverIds, MetricWindow $window): array
    {
        if ($serverIds === []) {
            return [];
        }

        $rows = DB::table('server_metric_samples')
            ->selectRaw('server_id, '.MetricWindow::BUCKET_SQL.', avg(cpu_pct) as cpu_pct, avg(mem_used_mb) as mem_used_mb, max(mem_total_mb) as mem_total_mb,
                avg(disk_used_mb) as disk_used_mb, max(disk_total_mb) as disk_total_mb, avg(net_rx_bps) as net_rx_bps, avg(net_tx_bps) as net_tx_bps, avg(load1) as load1', $window->bucketBinding())
            ->whereIn('server_id', $serverIds)
            ->where('resolution', $window->resolution())
            ->where('at', '>=', $window->from())
            ->groupBy('server_id', 'b')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $i = $window->index($row->b ?? null);

            if ($i === null) {
                continue;
            }

            $id = Cast::id($row->server_id ?? null);
            $out[$id] ??= array_fill_keys(self::HOST_KEYS, $window->empty());
            $memTotal = Cast::float($row->mem_total_mb ?? null);
            $diskTotal = Cast::float($row->disk_total_mb ?? null);
            $memUsed = Cast::float($row->mem_used_mb ?? null);
            $diskUsed = Cast::float($row->disk_used_mb ?? null);
            $values = [
                'cpu_pct' => Cast::round(Cast::float($row->cpu_pct ?? null), 1),
                'mem_used_mb' => Cast::round($memUsed, 0),
                'mem_total_mb' => Cast::round($memTotal, 0),
                'disk_total_mb' => Cast::round($diskTotal, 0),
                'mem_pct' => $memUsed !== null && $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : null,
                'disk_used_mb' => Cast::round($diskUsed, 0),
                'disk_pct' => $diskUsed !== null && $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null,
                'net_rx_bps' => Cast::round(Cast::float($row->net_rx_bps ?? null), 0),
                'net_tx_bps' => Cast::round(Cast::float($row->net_tx_bps ?? null), 0),
                'load1' => Cast::round(Cast::float($row->load1 ?? null), 2),
            ];

            foreach ($values as $key => $value) {
                $out[$id][$key][$i] = $value;
            }
        }

        return $out;
    }

    /**
     * The newest minute per server (≤ 5 minutes old).
     *
     * @param  list<int>  $serverIds
     * @return array<int, HostUsageNowData>
     */
    private function current(array $serverIds, CarbonImmutable $now): array
    {
        if ($serverIds === []) {
            return [];
        }

        $rows = DB::table('server_metric_samples')
            ->selectRaw('DISTINCT ON (server_id) server_id, at, cpu_pct, mem_used_mb, mem_total_mb, disk_used_mb, disk_total_mb, net_rx_bps, net_tx_bps, load1')
            ->whereIn('server_id', $serverIds)
            ->where('resolution', 1)
            ->where('at', '>=', $now->subMinutes(self::CURRENT_WITHIN_MINUTES)->toIso8601String())
            ->orderBy('server_id')
            ->orderByDesc('at')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $memUsed = Cast::int($row->mem_used_mb ?? null);
            $memTotal = Cast::int($row->mem_total_mb ?? null);
            $diskUsed = Cast::int($row->disk_used_mb ?? null);
            $diskTotal = Cast::int($row->disk_total_mb ?? null);
            $out[Cast::id($row->server_id ?? null)] = new HostUsageNowData(
                CarbonImmutable::parse(Cast::string($row->at ?? null))->utc()->toIso8601String(),
                Cast::round(Cast::float($row->cpu_pct ?? null), 1),
                $memUsed,
                $memTotal,
                $memUsed !== null && $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : null,
                $diskUsed,
                $diskTotal,
                $diskUsed !== null && $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : null,
                Cast::int($row->net_rx_bps ?? null),
                Cast::int($row->net_tx_bps ?? null),
                Cast::round(Cast::float($row->load1 ?? null), 2),
            );
        }

        return $out;
    }

    /**
     * Workload usage of each server's newest workload minute (≤ 5 minutes old).
     *
     * @param  list<int>  $serverIds
     * @return array<int, list<array{project_id: int, cpu: float, mem: int}>>
     */
    private function currentWorkloads(array $serverIds, CarbonImmutable $now): array
    {
        if ($serverIds === []) {
            return [];
        }

        $rows = DB::table('workload_metric_samples as w')
            ->whereIn('w.server_id', $serverIds)
            ->where('w.resolution', 1)
            ->whereRaw('w.at = (SELECT max(x.at) FROM workload_metric_samples x WHERE x.server_id = w.server_id AND x.resolution = 1 AND x.at >= ?)', [$now->subMinutes(self::CURRENT_WITHIN_MINUTES)->toIso8601String()])
            ->get(['w.server_id', 'w.project_id', 'w.cpu_cores', 'w.mem_mb']);

        $out = [];

        foreach ($rows as $row) {
            $out[Cast::id($row->server_id ?? null)][] = ['project_id' => Cast::id($row->project_id ?? null), 'cpu' => Cast::float($row->cpu_cores ?? null) ?? 0.0, 'mem' => Cast::int($row->mem_mb ?? null) ?? 0];
        }

        return $out;
    }

    /**
     * @param  list<array{project_id: int, cpu: float, mem: int}>  $workloads
     * @return list<ProjectUsageData>
     */
    private function usedBy(array $workloads, ?int $memTotal): array
    {
        if ($workloads === []) {
            return [];
        }

        $byProject = [];

        foreach ($workloads as $w) {
            $byProject[$w['project_id']] ??= ['cpu' => 0.0, 'mem' => 0];
            $byProject[$w['project_id']]['cpu'] += $w['cpu'];
            $byProject[$w['project_id']]['mem'] += $w['mem'];
        }

        uasort($byProject, static fn (array $a, array $b): int => $b['mem'] <=> $a['mem'] ?: $b['cpu'] <=> $a['cpu']);
        $top = array_slice($byProject, 0, self::TOP_PROJECTS, true);
        $rest = array_slice($byProject, self::TOP_PROJECTS, null, true);
        $projects = DB::table('projects')->whereIn('id', array_keys($top))->get(['id', 'public_id', 'name', 'color_slot'])->keyBy('id');
        $share = static fn (int $mem): ?float => $memTotal > 0 ? round($mem / $memTotal, 4) : null;
        $out = [];

        foreach ($top as $id => $usage) {
            $project = $projects->get($id);

            if (! is_object($project)) {
                continue;
            }

            $out[] = new ProjectUsageData(Cast::string($project->public_id ?? null), Cast::string($project->name ?? null), Cast::id($project->color_slot ?? null), $usage['mem'], round($usage['cpu'], 3), $share($usage['mem']));
        }

        if ($rest !== []) {
            $mem = array_sum(array_column($rest, 'mem'));
            $out[] = new ProjectUsageData(null, 'Other', null, $mem, round(array_sum(array_column($rest, 'cpu')), 3), $share($mem));
        }

        return $out;
    }

    /**
     * Per service instance on this server: CPU and memory summed over its
     * replicas per stored minute, then averaged per bucket.
     *
     * @return list<array{id: int, service_public_id: string, service_name: string, environment_public_id: string, environment_name: string, project_public_id: string, project_name: string, color_slot: int, cpu: array<int, float|null>, mem: array<int, float|null>, current_cpu: float|null, current_mem: int|null}>
     */
    private function serviceSeries(Server $server, MetricWindow $window): array
    {
        $rows = DB::query()
            ->fromSub(
                DB::table('workload_metric_samples')
                    ->selectRaw('service_instance_id, at, sum(cpu_cores) as cpu, sum(mem_mb) as mem')
                    ->where('server_id', $server->id)
                    ->where('resolution', $window->resolution())
                    ->where('at', '>=', $window->from())
                    ->groupBy('service_instance_id', 'at'),
                't',
            )
            ->selectRaw('service_instance_id, '.MetricWindow::BUCKET_SQL.', avg(cpu) as cpu, avg(mem) as mem', $window->bucketBinding())
            ->groupBy('service_instance_id', 'b')
            ->get();

        $current = [];

        foreach (DB::table('workload_metric_samples as w')
            ->where('w.server_id', $server->id)
            ->where('w.resolution', 1)
            ->whereRaw('w.at = (SELECT max(x.at) FROM workload_metric_samples x WHERE x.server_id = w.server_id AND x.resolution = 1 AND x.at >= ?)', [$window->now->subMinutes(self::CURRENT_WITHIN_MINUTES)->toIso8601String()])
            ->groupBy('w.service_instance_id')
            ->selectRaw('w.service_instance_id, sum(w.cpu_cores) as cpu, sum(w.mem_mb) as mem')
            ->get() as $row) {
            $current[Cast::id($row->service_instance_id ?? null)] = ['cpu' => Cast::round(Cast::float($row->cpu ?? null), 3), 'mem' => Cast::int($row->mem ?? null)];
        }

        $series = [];

        foreach ($rows as $row) {
            $i = $window->index($row->b ?? null);

            if ($i === null) {
                continue;
            }

            $id = Cast::id($row->service_instance_id ?? null);
            $series[$id] ??= ['cpu' => $window->empty(), 'mem' => $window->empty()];
            $series[$id]['cpu'][$i] = Cast::round(Cast::float($row->cpu ?? null), 3);
            $series[$id]['mem'][$i] = Cast::round(Cast::float($row->mem ?? null), 0);
        }

        $ids = array_values(array_unique([...array_keys($series), ...array_keys($current)]));

        return array_map(static fn (array $si): array => [
            'id' => $si['id'],
            'service_public_id' => $si['service_public_id'],
            'service_name' => $si['service_name'],
            'environment_public_id' => $si['environment_public_id'],
            'environment_name' => $si['environment_name'],
            'project_public_id' => $si['project_public_id'],
            'project_name' => $si['project_name'],
            'color_slot' => $si['color_slot'],
            'cpu' => $series[$si['id']]['cpu'] ?? $window->empty(),
            'mem' => $series[$si['id']]['mem'] ?? $window->empty(),
            'current_cpu' => $current[$si['id']]['cpu'] ?? null,
            'current_mem' => $current[$si['id']]['mem'] ?? null,
        ], self::instances($ids));
    }
}

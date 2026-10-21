<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Logs\LogBucketData;
use App\Data\Logs\LogEntryData;
use App\Data\Logs\LogExplorerPageData;
use App\Data\Logs\LogMarkerData;
use App\Data\Logs\LogQueryData;
use App\Enums\DeploymentStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Support\Logs\LogLevel;
use App\Support\Logs\LogScope;
use App\Support\Logs\LogSearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The log explorer's search (docs/08 §12, direction.md §9.8): every stored
 * line of an environment, a project or a team in a time range, filtered on
 * the server with the explorer's query language (LogSearchQuery).
 *
 * Storage (docs/notes/2026-10-log-search-benchmark.md): lines live in
 * `log_chunks` (one row per shipped batch) with the chunk's scope, runtime
 * flag and counts; text terms first narrow chunks through the trigram index
 * on `lr_log_text(lines)`, then lines are expanded (`jsonb_array_elements`)
 * and matched one by one. Histograms come from the chunk counts (index-only)
 * when nothing line-level is asked, otherwise from the matching lines.
 *
 * Returns the newest LIMIT matching lines (oldest first), the number of
 * matching lines in the range, 60 histogram buckets (all levels, errors
 * stacked) and deploy markers. `after` (a chunk id) returns only newer
 * chunks for live polling, without buckets.
 *
 * Callers authorize the scope; everything here is constrained to it.
 *
 * @phpstan-type LineRow array{chunk_id: int, server_id: int, daemon_task_id: int|null, deployment_id: int|null, source_kind: string, source_id: string, runtime: bool, project_id: int|null, environment_id: int|null, service_id: int|null, ord: int, line: string}
 */
final class LogSearch
{
    public const int LIMIT = 5000;

    public const int BUCKETS = 60;

    /** Statement timeout for one search, so a pathological query can't hold a worker. */
    private const int TIMEOUT_MS = 15000;

    public function page(LogScope $scope, LogQueryData $query, int $tailing = 0): LogExplorerPageData
    {
        $to = CarbonImmutable::now();
        $from = $to->subSeconds($query->seconds());
        $search = LogSearchQuery::parse($query->q);

        return DB::transaction(function () use ($scope, $query, $search, $from, $to, $tailing): LogExplorerPageData {
            DB::statement('SET LOCAL statement_timeout = '.self::TIMEOUT_MS);

            $chunkFilter = $this->chunkFilter($scope, $query, $search, $from);

            if (! $chunkFilter instanceof \Closure) {
                return $this->empty($from, $to, $query->after, $tailing);
            }

            $rows = $this->lines($chunkFilter, $search);
            $entries = $this->hydrate($scope, $rows);
            $cursor = max([$query->after, ...array_map(static fn (array $row): int => $row['chunk_id'], $rows)]);

            [$buckets, $matches] = $query->after > 0
                ? [[], count($entries)]
                : $this->histogram($chunkFilter, $search, $from, $to);

            return new LogExplorerPageData(
                entries: array_reverse($entries),
                markers: $query->after > 0 ? [] : $this->markers($scope, $query, $from),
                from: $from->toIso8601String(),
                to: $to->toIso8601String(),
                cursor: $cursor,
                truncated: count($rows) >= self::LIMIT,
                tailing: $tailing,
                buckets: $buckets,
                matches: max($matches, count($entries)),
            );
        });
    }

    /**
     * The chunk-level conditions; null when a filter can't match anything
     * (an unknown service or server name).
     */
    private function chunkFilter(LogScope $scope, LogQueryData $query, LogSearchQuery $search, CarbonImmutable $from): ?\Closure
    {
        $ids = [];

        foreach (['service' => $this->serviceIds(...), 'environment' => $this->environmentIds(...), 'project' => $this->projectIds(...), 'server' => $this->serverIds(...), 'deployment' => $this->deploymentIds(...)] as $key => $resolve) {
            $ids[$key] = [
                'in' => $search->positive($key) === [] ? null : $resolve($scope, $search->positive($key)),
                'out' => $search->negative($key) === [] ? [] : $resolve($scope, $search->negative($key)),
            ];

            if ($ids[$key]['in'] === []) {
                return null;
            }
        }

        $service = null;

        if ($query->service !== null) {
            $service = Service::query()->where('team_id', $scope->teamId)->where('public_id', $query->service)->value('id');

            if (! is_int($service)) {
                return null;
            }
        }

        $columns = ['service' => 'c.service_id', 'environment' => 'c.environment_id', 'project' => 'c.project_id', 'server' => 'c.server_id', 'deployment' => 'c.deployment_id'];
        $errorsOnly = $search->positive('level') === ['error'];

        return static function (Builder $q) use ($scope, $query, $search, $from, $ids, $columns, $service, $errorsOnly): void {
            $q->where('c.team_id', $scope->teamId)->where('c.created_at', '>=', $from);

            if ($scope->projectId !== null) {
                $q->where('c.project_id', $scope->projectId);
            } else {
                $q->whereNotNull('c.project_id');
            }

            if ($scope->environmentId !== null) {
                $q->where('c.environment_id', $scope->environmentId);
            }

            if ($service !== null) {
                $q->where('c.service_id', $service);
            }

            if ($query->source !== 'all') {
                $q->where('c.runtime', $query->source === 'runtime');
            }

            if ($query->after > 0) {
                $q->where('c.id', '>', $query->after);
            }

            foreach ($ids as $key => $set) {
                if ($set['in'] !== null) {
                    $q->whereIn($columns[$key], $set['in']);
                }

                if ($set['out'] !== []) {
                    $q->where(static fn (Builder $w) => $w->whereNull($columns[$key])->orWhereNotIn($columns[$key], $set['out']));
                }
            }

            if ($errorsOnly) {
                $q->where('c.error_count', '>', 0);
            }

            foreach ($search->text as $term) {
                $q->whereRaw('lr_log_text(c.lines) ILIKE ?', ['%'.self::escapeLike($term).'%']);
            }
        };
    }

    /**
     * Line-level conditions on `l.e` (text, level, stream).
     */
    private function lineFilter(Builder $q, LogSearchQuery $search, bool $withLevel = true): void
    {
        foreach ($search->text as $term) {
            $q->whereRaw("l.e->>'text' ILIKE ?", ['%'.self::escapeLike($term).'%']);
        }

        $fields = ['level' => [DB::raw("l.e->>'level'"), DB::raw("coalesce(l.e->>'level', '')")], 'stream' => [DB::raw("l.e->>'stream'"), DB::raw("coalesce(l.e->>'stream', '')")]];

        foreach ($withLevel ? ['level', 'stream'] : ['stream'] as $key) {
            if (($in = $search->positive($key)) !== []) {
                $q->whereIn($fields[$key][0], $in);
            }

            if (($out = $search->negative($key)) !== []) {
                $q->whereNotIn($fields[$key][1], $out);
            }
        }
    }

    /**
     * @return list<LineRow>
     */
    private function lines(\Closure $chunkFilter, LogSearchQuery $search): array
    {
        $q = DB::query()
            ->fromRaw('log_chunks c CROSS JOIN LATERAL jsonb_array_elements(c.lines) WITH ORDINALITY AS l(e, ord)')
            ->select(['c.id as chunk_id', 'c.server_id', 'c.daemon_task_id', 'c.deployment_id', 'c.source_kind', 'c.source_id', 'c.runtime', 'c.project_id', 'c.environment_id', 'c.service_id', 'l.ord', DB::raw('l.e::text as line')])
            ->where($chunkFilter);
        $this->lineFilter($q, $search);

        /** @var list<LineRow> */
        return $q->latest('c.created_at')->orderByDesc('c.id')->orderByDesc('l.ord')->limit(self::LIMIT)->get()->map(static fn (object $row): array => (array) $row)->values()->all();
    }

    /**
     * 60 buckets (all levels; the level filter only narrows `matches`) and
     * the number of lines matching the whole query.
     *
     * @return array{list<LogBucketData>, int}
     */
    private function histogram(\Closure $chunkFilter, LogSearchQuery $search, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $width = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / self::BUCKETS));
        $bin = ['date_bin(make_interval(secs => ?), c.created_at, ?::timestamptz) as bucket', [$width, $from->utc()->format('Y-m-d H:i:sP')]];
        $levels = $search->filters['level'] ?? [];
        $byChunk = $search->text === [] && ! $search->has('stream') && ($levels === [] || $search->positive('level') === ['error'] && $search->negative('level') === []);

        if ($byChunk) {
            $q = DB::table('log_chunks as c')
                ->selectRaw($bin[0], $bin[1])
                ->selectRaw('sum(c.line_count) as total, sum(c.error_count) as errors')
                ->selectRaw($levels === [] ? 'sum(c.line_count) as matched' : 'sum(c.error_count) as matched');
        } else {
            $kept = $this->levels($search);
            $q = DB::query()
                ->fromRaw('log_chunks c CROSS JOIN LATERAL jsonb_array_elements(c.lines) AS l(e)')
                ->selectRaw($bin[0], $bin[1])
                ->selectRaw("count(*) as total, count(*) FILTER (WHERE l.e->>'level' = 'error') as errors");

            if ($kept === null) {
                $q->selectRaw('count(*) as matched');
            } else {
                $q->selectRaw("count(*) FILTER (WHERE l.e->>'level' = ANY(string_to_array(?, ','))) as matched", [implode(',', $kept)]);
            }
            $this->lineFilter($q, $search, withLevel: false);
        }

        $rows = $q->where($chunkFilter)->groupBy('bucket')->get()->keyBy(static fn (object $row): int => CarbonImmutable::parse(is_string($row->bucket) ? $row->bucket : 'now')->getTimestamp());

        $buckets = [];
        $matches = 0;
        $start = $from->getTimestamp();

        for ($i = 0; $i < self::BUCKETS; $i++) {
            $at = $start + $i * $width;
            $row = (array) ($rows->get($at) ?? []);
            $buckets[] = new LogBucketData(
                start: $at * 1000,
                end: ($at + $width) * 1000,
                total: is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0,
                errors: is_numeric($row['errors'] ?? null) ? (int) $row['errors'] : 0,
            );
        }

        foreach ($rows as $row) {
            $matched = ((array) $row)['matched'] ?? null;
            $matches += is_numeric($matched) ? (int) $matched : 0;
        }

        return [$buckets, $matches];
    }

    /**
     * The levels the query keeps (positive ones, else all, minus negative
     * ones), for the `matched` count; unknown levels match nothing.
     *
     * @return list<string>|null null: no level filter
     */
    private function levels(LogSearchQuery $search): ?array
    {
        if (! $search->has('level')) {
            return null;
        }

        $in = $search->positive('level') === [] ? LogLevel::LEVELS : array_intersect(LogLevel::LEVELS, $search->positive('level'));

        return array_values(array_diff($in, $search->negative('level')));
    }

    /**
     * Rows → explorer lines (newest first), with names for the scope.
     *
     * @param  list<LineRow>  $rows
     * @return list<LogEntryData>
     */
    private function hydrate(LogScope $scope, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $pluck = static fn (string $key): array => array_values(array_unique(array_filter(array_column($rows, $key), is_int(...))));

        $deployments = Deployment::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('deployment_id'))->get(['id', 'public_id', 'commit_sha'])->keyBy('id');
        $services = Service::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('service_id'))->get(['id', 'public_id', 'name'])->keyBy('id');
        $servers = Server::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('server_id'))->pluck('name', 'id');
        $environments = $scope->kind === 'environment' ? collect() : Environment::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('environment_id'))->pluck('name', 'id');
        $projects = $scope->kind === 'team' ? Project::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('project_id'))->pluck('name', 'id') : collect();
        $tasks = DaemonTask::query()->where('team_id', $scope->teamId)->whereIn('id', $pluck('daemon_task_id'))->get(['id', 'spec'])->keyBy('id');

        $workloadOf = static function (array $row) use ($tasks): ?string {
            if ($row['source_kind'] === 'workload') {
                return is_string($row['source_id']) ? $row['source_id'] : null;
            }

            $task = is_int($row['daemon_task_id'] ?? null) ? $tasks->get($row['daemon_task_id']) : null;
            $workload = $task instanceof DaemonTask ? ($task->spec->workload ?? null) : null;

            return is_string($workload) ? $workload : null;
        };
        $workloads = array_values(array_unique(array_filter(array_map($workloadOf, $rows), is_string(...))));
        $slots = $workloads === [] ? collect() : Placement::query()->where('team_id', $scope->teamId)->whereIn('workload_id', $workloads)->pluck('slot', 'workload_id');

        $entries = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $line */
            $line = json_decode($row['line'], true, 8, JSON_THROW_ON_ERROR);
            $text = is_string($line['text'] ?? null) ? $line['text'] : '';
            $stream = is_string($line['stream'] ?? null) ? $line['stream'] : 'stdout';
            $deployment = $row['deployment_id'] === null ? null : $deployments->get($row['deployment_id']);
            $service = $row['service_id'] === null ? null : $services->get($row['service_id']);
            $workload = $workloadOf($row);
            $slot = $workload === null ? null : $slots->get($workload);
            $environment = $row['environment_id'] === null ? null : $environments->get($row['environment_id']);
            $project = $row['project_id'] === null ? null : $projects->get($row['project_id']);
            $server = $servers->get($row['server_id']);

            $entries[] = new LogEntryData(
                id: $row['chunk_id'].'-'.($row['ord'] - 1),
                time: is_string($line['time'] ?? null) ? $line['time'] : '',
                level: LogLevel::of($line),
                source: $row['runtime'] ? 'runtime' : 'build',
                service: $service instanceof Service ? $service->name : 'launchroom',
                serviceId: $service instanceof Service ? $service->public_id : null,
                replica: is_int($slot) ? (string) ($slot + 1) : null,
                server: is_string($server) ? $server : null,
                deployment: $deployment instanceof Deployment ? ($deployment->commit_sha !== null ? substr($deployment->commit_sha, 0, 7) : $deployment->public_id) : null,
                deploymentId: $deployment instanceof Deployment ? $deployment->public_id : null,
                stream: in_array($stream, ['stdout', 'stderr', 'system'], true) ? $stream : 'stdout',
                message: mb_substr($text, 0, 8000),
                environment: is_string($environment) ? $environment : null,
                project: is_string($project) ? $project : null,
            );
        }

        return $entries;
    }

    /**
     * @return list<LogMarkerData>
     */
    private function markers(LogScope $scope, LogQueryData $query, CarbonImmutable $from): array
    {
        $deployments = Deployment::query()
            ->where('team_id', $scope->teamId)
            ->when($scope->environmentId !== null, static fn ($q) => $q->where('environment_id', $scope->environmentId))
            ->when($scope->kind === 'project', static fn ($q) => $q->whereIn('environment_id', Environment::query()->select('id')->where('project_id', $scope->projectId)))
            ->when($query->service !== null, static fn ($q) => $q->whereHas('serviceInstance.service', static fn (\Illuminate\Contracts\Database\Query\Builder $s) => $s->where('public_id', $query->service)))
            ->whereIn('status', [DeploymentStatus::Active, DeploymentStatus::Superseded, DeploymentStatus::Failed])
            ->where(static fn (\Illuminate\Contracts\Database\Query\Builder $q) => $q->where('finished_at', '>=', $from)->orWhere(static fn (\Illuminate\Contracts\Database\Query\Builder $w) => $w->whereNull('finished_at')->where('queued_at', '>=', $from)))
            ->with('serviceInstance.service:id,name')
            ->latest('queued_at')
            ->limit(200)
            ->get(['id', 'public_id', 'service_instance_id', 'commit_sha', 'status', 'queued_at', 'finished_at']);

        $markers = [];

        foreach ($deployments as $deployment) {
            $markers[] = new LogMarkerData(
                deploymentId: $deployment->public_id,
                at: ($deployment->finished_at ?? $deployment->queued_at)->toIso8601String(),
                label: ($deployment->status === DeploymentStatus::Failed ? 'failed ' : 'deploy ').($deployment->commit_sha !== null ? substr($deployment->commit_sha, 0, 7) : $deployment->serviceInstance->service->name),
                status: $deployment->status->value,
            );
        }

        return array_reverse($markers);
    }

    private function empty(CarbonImmutable $from, CarbonImmutable $to, int $after, int $tailing): LogExplorerPageData
    {
        $width = (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / self::BUCKETS);
        $buckets = [];

        for ($i = 0; $i < self::BUCKETS; $i++) {
            $at = $from->getTimestamp() + $i * $width;
            $buckets[] = new LogBucketData($at * 1000, ($at + $width) * 1000, 0, 0);
        }

        return new LogExplorerPageData([], [], $from->toIso8601String(), $to->toIso8601String(), $after, false, $tailing, $buckets, 0);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function serviceIds(LogScope $scope, array $names): array
    {
        return $this->ids(Service::query()->where('team_id', $scope->teamId)->when($scope->projectId !== null, static fn ($q) => $q->where('project_id', $scope->projectId)), $names, ['name', 'slug']);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function environmentIds(LogScope $scope, array $names): array
    {
        return $this->ids(Environment::query()->where('team_id', $scope->teamId)->when($scope->projectId !== null, static fn ($q) => $q->where('project_id', $scope->projectId)), $names, ['name', 'slug']);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function projectIds(LogScope $scope, array $names): array
    {
        return $this->ids(Project::query()->where('team_id', $scope->teamId), $names, ['name', 'slug']);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function serverIds(LogScope $scope, array $names): array
    {
        return $this->ids(Server::query()->where('team_id', $scope->teamId), $names, ['name']);
    }

    /**
     * Short SHAs (any prefix of 4+ hex characters) or deployment ids.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    private function deploymentIds(LogScope $scope, array $values): array
    {
        $q = Deployment::query()->where('team_id', $scope->teamId)->where(static function (\Illuminate\Contracts\Database\Query\Builder $q) use ($values): void {
            foreach ($values as $value) {
                $q->orWhere('public_id', $value);

                if (preg_match('/^[0-9a-f]{4,64}$/i', $value) === 1) {
                    $q->orWhere('commit_sha', 'like', strtolower($value).'%');
                }
            }
        });

        /** @var list<int> */
        return $q->limit(1000)->pluck('id')->all();
    }

    /**
     * Ids of rows whose name (or slug) equals one of the values, case-insensitive.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant Model>  $query
     * @param  list<string>  $values
     * @param  list<string>  $columns
     * @return list<int>
     */
    private function ids(\Illuminate\Database\Eloquent\Builder $query, array $values, array $columns): array
    {
        $lower = array_map(mb_strtolower(...), $values);
        $query->where(static function (\Illuminate\Contracts\Database\Query\Builder $q) use ($lower, $columns): void {
            foreach ($columns as $column) {
                $q->orWhereIn($column === 'slug' ? DB::raw('lower(slug)') : DB::raw('lower(name)'), $lower);
            }
        });

        /** @var list<int> */
        return $query->limit(1000)->pluck('id')->all();
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}

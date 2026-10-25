<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Analytics\AnalyticsProjectData;
use App\Data\Analytics\AnalyticsSplitData;
use App\Data\Analytics\AnalyticsTeamPathData;
use App\Data\Analytics\AnalyticsTotalsData;
use App\Data\Analytics\TeamAnalyticsData;
use App\Enums\AnalyticsRange;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The team analytics overview (docs/15 §5.4): every project of the team,
 * all environments, in a fixed number of grouped queries regardless of the
 * project count (counts per project, server and bucket; the previous
 * period per project; top paths per project and path; the covered
 * traffic). Reads the same tiered resolutions as RequestAnalytics and uses
 * the (`project_id`, `resolution`, `at`) indexes. Callers authorize.
 */
final readonly class TeamAnalytics
{
    public const int TOP_PATHS = 10;

    public function handle(Team $team, AnalyticsRange $range, ?CarbonImmutable $now = null): TeamAnalyticsData
    {
        $now ??= CarbonImmutable::now();
        $w = AnalyticsTiers::window($range, $now);
        $empty = array_fill(0, $w['count'], 0);
        $byProject = [];
        $byServer = [];
        $totals = ['requests' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0];

        $rows = $this->scoped('request_metric_samples', $team, $range, $now, $w['from'], null, AnalyticsTiers::REQUESTS)
            ->selectRaw("project_id, server_id, extract(epoch from date_bin(make_interval(secs => ?), at, TIMESTAMPTZ 'epoch'))::bigint as b, sum(requests) as requests, sum(status_2xx) as s2, sum(status_3xx) as s3, sum(status_4xx) as s4, sum(status_5xx) as s5", [$w['bucket']])
            ->groupBy('project_id', 'server_id', 'b')
            ->get();

        foreach ($rows as $row) {
            $b = Cast::int($row->b ?? null);
            $i = $b === null ? -1 : intdiv($b - $w['start'], $w['bucket']);

            if ($i < 0 || $i >= $w['count']) {
                continue;
            }

            $requests = Cast::int($row->requests ?? null) ?? 0;
            $s4 = Cast::int($row->s4 ?? null) ?? 0;
            $s5 = Cast::int($row->s5 ?? null) ?? 0;
            $totals['requests'] += $requests;
            $totals['s2'] += Cast::int($row->s2 ?? null) ?? 0;
            $totals['s3'] += Cast::int($row->s3 ?? null) ?? 0;
            $totals['s4'] += $s4;
            $totals['s5'] += $s5;

            $project = Cast::id($row->project_id ?? null);
            $agg = $byProject[$project] ?? ['requests' => 0, 's4' => 0, 's5' => 0, 'series' => $empty];
            $agg['requests'] += $requests;
            $agg['s4'] += $s4;
            $agg['s5'] += $s5;
            $agg['series'][$i] = ($agg['series'][$i] ?? 0) + $requests;
            $byProject[$project] = $agg;

            $server = Cast::id($row->server_id ?? null);
            $split = $byServer[$server] ?? ['requests' => 0, 's5' => 0, 'series' => $empty];
            $split['requests'] += $requests;
            $split['s5'] += $s5;
            $split['series'][$i] = ($split['series'][$i] ?? 0) + $requests;
            $byServer[$server] = $split;
        }

        $previous = [];

        foreach ($this->scoped('request_metric_samples', $team, $range, $now, $w['previousFrom'], $w['from'], AnalyticsTiers::REQUESTS)
            ->selectRaw('project_id, sum(requests) as requests, sum(status_5xx) as s5')
            ->groupBy('project_id')
            ->get() as $row) {
            $previous[Cast::id($row->project_id ?? null)] = [Cast::int($row->requests ?? null) ?? 0, Cast::int($row->s5 ?? null) ?? 0];
        }

        $previousRequests = array_sum(array_column($previous, 0));
        $previousErrors = array_sum(array_column($previous, 1));
        [$paths, $pathRequests] = $this->paths($team, $range, $now, $w['from']);

        return new TeamAnalyticsData(
            range: $range->value,
            bucketSeconds: $w['bucket'],
            timestamps: $w['timestamps'],
            totals: new AnalyticsTotalsData(
                $totals['requests'], $totals['s2'], $totals['s3'], $totals['s4'], $totals['s5'],
                $this->rate($totals['s5'], $totals['requests']),
                $previousRequests > 0 ? $previousRequests : null,
                $previousRequests > 0 ? $this->rate($previousErrors, $previousRequests) : null,
            ),
            projects: $this->projects($team, $byProject, $previous),
            servers: $this->servers($byServer),
            paths: $paths,
            pathRequests: $pathRequests,
        );
    }

    /**
     * Samples of the team's projects in [from, to), tiered.
     *
     * @param  array{int, int, int}  $resolutions
     */
    private function scoped(string $table, Team $team, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from, ?CarbonImmutable $to, array $resolutions): Builder
    {
        return AnalyticsTiers::apply(
            DB::table($table)->whereIn('project_id', DB::table('projects')->select('id')->where('team_id', $team->id)),
            $range, $now, $from, $to, $resolutions,
        );
    }

    /**
     * @param  array<int, array{requests: int, s4: int, s5: int, series: array<int, int>}>  $byProject
     * @param  array<int, array{int, int}>  $previous
     * @return list<AnalyticsProjectData>
     */
    private function projects(Team $team, array $byProject, array $previous): array
    {
        if ($byProject === []) {
            return [];
        }

        $projects = Project::query()->where('team_id', $team->id)->whereKey(array_keys($byProject))->get(['id', 'public_id', 'name', 'color_slot'])->keyBy('id');
        $production = Environment::query()
            ->whereIn('project_id', array_keys($byProject))
            ->where('kind', EnvironmentKind::Production->value)
            ->pluck('public_id', 'project_id')
            ->all();
        $out = [];

        foreach ($byProject as $id => $agg) {
            $project = $projects->get($id);

            if (! $project instanceof Project) {
                continue;
            }

            $before = $previous[$id][0] ?? 0;
            $env = $production[$id] ?? null;
            $out[] = new AnalyticsProjectData(
                $project->public_id,
                $project->name,
                $project->color_slot,
                is_string($env) ? $env : null,
                $agg['requests'],
                $before > 0 ? $before : null,
                $this->rate($agg['s5'], $agg['requests']),
                $this->rate($agg['s4'], $agg['requests']),
                array_values($agg['series']),
            );
        }

        usort($out, static fn (AnalyticsProjectData $a, AnalyticsProjectData $b): int => [$b->requests, $a->name] <=> [$a->requests, $b->name]);

        return $out;
    }

    /**
     * @param  array<int, array{requests: int, s5: int, series: array<int, int>}>  $byServer
     * @return list<AnalyticsSplitData>
     */
    private function servers(array $byServer): array
    {
        if ($byServer === []) {
            return [];
        }

        $servers = Server::query()->whereKey(array_keys($byServer))->get(['id', 'public_id', 'name', 'status'])->keyBy('id');
        $out = [];

        foreach ($byServer as $id => $agg) {
            $server = $servers->get($id);

            if ($server instanceof Server) {
                $out[] = new AnalyticsSplitData($server->public_id, $server->name, $agg['requests'], $this->rate($agg['s5'], $agg['requests']), array_values($agg['series']), $server->status === ServerStatus::Revoked);
            }
        }

        usort($out, static fn (AnalyticsSplitData $a, AnalyticsSplitData $b): int => [$b->requests, $a->name] <=> [$a->requests, $b->name]);

        return $out;
    }

    /**
     * Top paths across all projects and the traffic the path data covers.
     *
     * @return array{list<AnalyticsTeamPathData>, int}
     */
    private function paths(Team $team, AnalyticsRange $range, CarbonImmutable $now, CarbonImmutable $from): array
    {
        $covered = Cast::int($this->scoped('request_analytics_samples', $team, $range, $now, $from, null, AnalyticsTiers::ANALYTICS)->sum('requests')) ?? 0;

        if ($covered <= 0) {
            return [[], 0];
        }

        $rows = DB::query()
            ->fromSub($this->scoped('request_analytics_samples', $team, $range, $now, $from, null, AnalyticsTiers::ANALYTICS)->select(['project_id', 'paths']), 's')
            ->crossJoin(DB::raw('jsonb_array_elements(s.paths) as e'))
            ->selectRaw("s.project_id, e->>'p' as path, sum((e->>'r')::bigint) as r, sum((e->>'e4')::bigint) as e4, sum((e->>'e5')::bigint) as e5")
            ->groupByRaw("s.project_id, e->>'p'")
            ->orderByDesc('r')
            ->orderBy('path')
            ->limit(self::TOP_PATHS)
            ->get();
        $names = Project::query()->where('team_id', $team->id)->whereKey($rows->pluck('project_id')->all())->get(['id', 'public_id', 'name'])->keyBy('id');
        $out = [];

        foreach ($rows as $row) {
            $project = $names->get(Cast::id($row->project_id ?? null));

            if ($project instanceof Project) {
                $out[] = new AnalyticsTeamPathData($project->public_id, $project->name, Cast::string($row->path ?? null), Cast::int($row->r ?? null) ?? 0, Cast::int($row->e4 ?? null) ?? 0, Cast::int($row->e5 ?? null) ?? 0);
            }
        }

        return [$out, $covered];
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 2) : null;
    }
}

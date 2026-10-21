<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\Alerts\Alert;
use App\Models\Domain;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Alerts\AlertRuleSettings;
use App\Services\Alerts\ResolvedRule;
use App\Support\Hostname;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The per-minute evaluator of the condition rules (docs/15 §3.4,
 * `launchroom:alerts:evaluate`): for each rule it computes the failing
 * subjects of every team from recent rows only (≤ 30 minutes), raises them
 * (RaiseAlert dedups) and resolves the rule's firing alerts whose subject
 * is no longer failing. Thresholds have hysteresis (a firing alert resolves
 * below a lower bar), so a value hovering at the threshold doesn't flap.
 *
 * Idempotent. Time-based state (how long a service has been unhealthy,
 * restart counts) lives in the cache for ≤ 15 minutes; losing it only
 * delays an alert by a few minutes.
 */
final readonly class EvaluateAlertConditions
{
    /** Minutes a service must be unhealthy before it alerts, and healthy before it resolves. */
    public const int UNHEALTHY_MINUTES = 3;

    /** Restarts within CRASH_WINDOW_MINUTES that count as crash-looping. */
    public const int CRASH_RESTARTS = 3;

    public const int CRASH_WINDOW_MINUTES = 10;

    /** Requests needed in the window before an error rate means anything. */
    public const int MIN_REQUESTS = 50;

    private const int WINDOW_MINUTES = 30;

    public function __construct(
        private AlertRuleSettings $settings,
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
        private Cache $cache,
    ) {}

    /**
     * @return array{raised: int, resolved: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $teamIds = array_values(array_map(Cast::id(...), Team::query()->pluck('id')->all()));
        $rules = $this->settings->forTeams($teamIds);
        $counts = ['raised' => 0, 'resolved' => 0];

        foreach (AlertRule::cases() as $rule) {
            if (! $rule->isCondition()) {
                continue;
            }

            /** @var array<string, true> $firing "team|subject" */
            $firing = [];

            foreach (Alert::query()->where('rule', $rule->value)->where('status', AlertStatus::Firing->value)->get(['team_id', 'subject_key']) as $alert) {
                $firing[$alert->team_id.'|'.$alert->subject_key] = true;
            }

            $failing = match ($rule) {
                AlertRule::ServiceUnhealthy => $this->unhealthyServices($firing, $now),
                AlertRule::ErrorRateHigh => $this->errorRates($rules, $firing, $now),
                AlertRule::CpuHigh, AlertRule::MemoryHigh => $this->serverLoad($rule, $rules, $firing, $now),
                AlertRule::DiskFull => $this->disks($rules, $firing, $now),
                AlertRule::CertificateExpiring => $this->certificates($rules, $now),
                default => [],
            };

            foreach ($failing as $key => $signal) {
                $teamId = (int) explode('|', $key, 2)[0];

                if (! ($rules[$teamId][$rule->value] ?? null)?->enabled) {
                    continue;
                }

                if ($this->raise->handle($signal()) instanceof Alert && ! isset($firing[$key])) {
                    $counts['raised']++;
                }
            }

            foreach (array_keys($firing) as $key) {
                if (isset($failing[$key])) {
                    continue;
                }

                [$teamId, $subject] = explode('|', $key, 2);

                if ($this->resolve->handle((int) $teamId, $rule, $subject, $this->resolvedReason($rule)) instanceof Alert) {
                    $counts['resolved']++;
                }
            }
        }

        return $counts;
    }

    private function resolvedReason(AlertRule $rule): string
    {
        return match ($rule) {
            AlertRule::ServiceUnhealthy => 'Every replica is healthy again.',
            AlertRule::ErrorRateHigh => 'Requests succeed again.',
            AlertRule::CpuHigh => 'CPU is back to normal.',
            AlertRule::MemoryHigh => 'Memory use is back to normal.',
            AlertRule::DiskFull => 'There is enough free disk space again.',
            AlertRule::CertificateExpiring => 'The certificate was renewed.',
            default => 'It is back to normal.',
        };
    }

    /**
     * Services whose live deployment has a replica down, unhealthy or
     * crash-looping, for UNHEALTHY_MINUTES (firing ones until they've been
     * healthy that long). Previews never alert; replicas on unreachable or
     * offline servers are the server's alert, not the service's.
     *
     * @param  array<string, true>  $firing
     * @return array<string, Closure(): AlertSignal>
     */
    private function unhealthyServices(array $firing, CarbonImmutable $now): array
    {
        $rows = DB::table('placements as p')
            ->join('service_instances as si', static fn (JoinClause $join): JoinClause => $join->on('si.id', '=', 'p.service_instance_id')->on('si.active_deployment_id', '=', 'p.deployment_id'))
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('servers as s', 's.id', '=', 'p.server_id')
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->where('s.status', ServerStatus::Online->value)
            ->get(['p.workload_id', 'p.service_instance_id', 'p.phase', 'p.restart_count', 'p.message', 'si.team_id']);

        /** @var array<int, array{team: int, total: int, down: int, restarts: int, message: string|null}> $instances */
        $instances = [];

        foreach ($rows as $row) {
            $id = Cast::id($row->service_instance_id ?? null);
            $phase = WorkloadPhase::tryFrom(Cast::string($row->phase ?? null));
            $restarts = $this->restartsInWindow(Cast::string($row->workload_id ?? null), Cast::id($row->restart_count ?? null), $now);
            $down = in_array($phase, [WorkloadPhase::Unhealthy, WorkloadPhase::Exited, WorkloadPhase::Failed], true);
            $instances[$id] ??= ['team' => Cast::id($row->team_id ?? null), 'total' => 0, 'down' => 0, 'restarts' => 0, 'message' => null];
            $instances[$id]['total']++;
            $instances[$id]['restarts'] = max($instances[$id]['restarts'], $restarts);

            if ($down || $restarts >= self::CRASH_RESTARTS) {
                $instances[$id]['down']++;
                $message = $row->message ?? null;
                $instances[$id]['message'] ??= is_string($message) && $message !== '' ? mb_substr($message, 0, 160) : null;
            }
        }

        $out = [];

        foreach ($instances as $id => $state) {
            $bad = $state['down'] > 0;
            $badFor = $this->since("alerts:unhealthy:{$id}", $bad, $now);
            $goodFor = $this->since("alerts:healthy:{$id}", ! $bad, $now);
            $instance = ServiceInstance::query()->whereKey($id)->with(['service', 'environment.project'])->first();

            if (! $instance instanceof ServiceInstance) {
                continue;
            }

            $key = $state['team'].'|service:'.$instance->public_id;
            $failing = isset($firing[$key])
                ? ($bad || $goodFor < self::UNHEALTHY_MINUTES * 60)
                : ($bad && $badFor >= self::UNHEALTHY_MINUTES * 60);

            if (! $failing) {
                continue;
            }

            $out[$key] = function () use ($instance, $state): AlertSignal {
                $name = $instance->service->name;
                $environment = $instance->environment;
                $crashing = $state['restarts'] >= self::CRASH_RESTARTS;
                $facts = ['Replicas' => ($state['total'] - $state['down']).' of '.$state['total'].' healthy'];

                if ($state['restarts'] > 0) {
                    $facts['Restarts'] = $state['restarts'].' in '.self::CRASH_WINDOW_MINUTES.' min';
                }

                if ($state['message'] !== null) {
                    $facts['Last message'] = $state['message'];
                }

                return new AlertSignal(
                    team: $this->team($instance->team_id),
                    rule: AlertRule::ServiceUnhealthy,
                    subject: 'service:'.$instance->public_id,
                    title: $crashing ? "{$name} keeps restarting in {$environment->name}" : "{$name} is unhealthy in {$environment->name}",
                    summary: $crashing
                        ? "It restarted {$state['restarts']} times in the last ".self::CRASH_WINDOW_MINUTES.' minutes. Its logs usually say why.'
                        : ($state['down'] === $state['total']
                            ? 'No replica is passing its health check. Its logs usually say why.'
                            : "{$state['down']} of {$state['total']} replicas are down; the others keep serving."),
                    severity: $environment->kind === EnvironmentKind::Production ? AlertSeverity::Critical : AlertSeverity::Warning,
                    href: "/p/{$environment->project->public_id}/{$environment->public_id}/s/{$instance->service->public_id}/logs",
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $facts,
                );
            };
        }

        return $out;
    }

    /**
     * Restarts of one workload within the crash window, from restart-count
     * readings kept in the cache (one per evaluation).
     */
    private function restartsInWindow(string $workload, int $count, CarbonImmutable $now): int
    {
        $key = "alerts:restarts:{$workload}";
        $cutoff = $now->subMinutes(self::CRASH_WINDOW_MINUTES)->getTimestamp();
        $stored = $this->cache->get($key);
        /** @var list<array{0: int, 1: int}> $readings */
        $readings = is_array($stored) ? array_values(array_filter($stored, static fn (mixed $r): bool => is_array($r) && is_int($r[0] ?? null) && is_int($r[1] ?? null) && $r[0] >= $cutoff)) : [];
        $readings[] = [$now->getTimestamp(), $count];
        $this->cache->put($key, $readings, (self::CRASH_WINDOW_MINUTES + 5) * 60);
        $oldest = min(array_map(static fn (array $r): int => $r[1], $readings));

        return max(0, $count - $oldest);
    }

    /**
     * Seconds a condition has held continuously (0 when it doesn't hold now).
     */
    private function since(string $key, bool $holds, CarbonImmutable $now): int
    {
        if (! $holds) {
            $this->cache->forget($key);

            return 0;
        }

        $start = $this->cache->get($key);

        if (! is_int($start)) {
            $start = $now->getTimestamp();
            $this->cache->put($key, $start, 15 * 60);
        } else {
            $this->cache->put($key, $start, 15 * 60);
        }

        return $now->getTimestamp() - $start;
    }

    /**
     * Services whose 5xx share over the team's window passes the threshold
     * (at least MIN_REQUESTS requests; firing ones resolve below half of it).
     *
     * @param  array<int, array<string, ResolvedRule>>  $rules
     * @param  array<string, true>  $firing
     * @return array<string, Closure(): AlertSignal>
     */
    private function errorRates(array $rules, array $firing, CarbonImmutable $now): array
    {
        $rows = DB::table('request_metric_samples as r')
            ->join('service_instances as si', 'si.id', '=', 'r.service_instance_id')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->where('r.resolution', 1)
            ->where('r.at', '>=', $now->subMinutes(self::WINDOW_MINUTES)->toIso8601String())
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->groupBy('r.service_instance_id', 'si.team_id', 'r.at')
            ->selectRaw('r.service_instance_id, si.team_id, r.at, sum(r.requests) as requests, sum(r.status_5xx) as errors')
            ->get();

        /** @var array<int, array{team: int, requests: int, errors: int}> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $team = Cast::id($row->team_id ?? null);
            $rule = $rules[$team][AlertRule::ErrorRateHigh->value] ?? null;
            $at = CarbonImmutable::parse(Cast::string($row->at ?? null));

            if ($rule === null || $at->lessThan($now->subMinutes($rule->duration ?? 5))) {
                continue;
            }

            $id = Cast::id($row->service_instance_id ?? null);
            $totals[$id] ??= ['team' => $team, 'requests' => 0, 'errors' => 0];
            $totals[$id]['requests'] += Cast::id($row->requests ?? null);
            $totals[$id]['errors'] += Cast::id($row->errors ?? null);
        }

        $out = [];

        foreach ($totals as $id => $total) {
            if ($total['requests'] < self::MIN_REQUESTS) {
                continue;
            }

            $rule = $rules[$total['team']][AlertRule::ErrorRateHigh->value];
            $threshold = $rule->threshold ?? 5.0;
            $rate = 100 * $total['errors'] / $total['requests'];
            $instance = ServiceInstance::query()->whereKey($id)->with(['service', 'environment.project'])->first();

            if (! $instance instanceof ServiceInstance) {
                continue;
            }

            $key = $total['team'].'|service:'.$instance->public_id;

            if ($rate < (isset($firing[$key]) ? $threshold / 2 : $threshold)) {
                continue;
            }

            $out[$key] = function () use ($instance, $total, $rate, $rule): AlertSignal {
                $environment = $instance->environment;
                $minutes = $rule->duration ?? 5;

                return new AlertSignal(
                    team: $this->team($instance->team_id),
                    rule: AlertRule::ErrorRateHigh,
                    subject: 'service:'.$instance->public_id,
                    title: "{$instance->service->name} is answering with errors in {$environment->name}",
                    summary: $this->percent($rate)." of requests failed with a server error in the last {$minutes} minutes.",
                    severity: $rate >= 25 ? AlertSeverity::Critical : AlertSeverity::Warning,
                    href: "/p/{$environment->project->public_id}/{$environment->public_id}/s/{$instance->service->public_id}/logs",
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: ['Errors' => number_format($total['errors']).' of '.number_format($total['requests']).' requests'],
                );
            };
        }

        return $out;
    }

    /**
     * Servers whose average CPU or memory over the team's window passes the
     * threshold (at least 60 % of the minutes reported; resolves 10 points lower).
     *
     * @param  array<int, array<string, ResolvedRule>>  $rules
     * @param  array<string, true>  $firing
     * @return array<string, Closure(): AlertSignal>
     */
    private function serverLoad(AlertRule $rule, array $rules, array $firing, CarbonImmutable $now): array
    {
        $servers = $this->activeServers();
        $rows = DB::table('server_metric_samples')
            ->whereIn('server_id', array_keys($servers))
            ->where('resolution', 1)
            ->where('at', '>=', $now->subMinutes(self::WINDOW_MINUTES)->toIso8601String())
            ->get(['server_id', 'at', 'cpu_pct', 'mem_used_mb', 'mem_total_mb']);

        /** @var array<int, list<float>> $values */
        $values = [];

        foreach ($rows as $row) {
            $server = $servers[Cast::id($row->server_id ?? null)] ?? null;
            $settings = $server === null ? null : ($rules[$server->team_id][$rule->value] ?? null);

            if ($server === null || $settings === null || CarbonImmutable::parse(Cast::string($row->at ?? null))->lessThan($now->subMinutes($settings->duration ?? 10))) {
                continue;
            }

            $value = $rule === AlertRule::CpuHigh
                ? Cast::float($row->cpu_pct ?? null)
                : (Cast::float($row->mem_total_mb ?? null) > 0 ? 100 * (Cast::float($row->mem_used_mb ?? null) ?? 0) / Cast::float($row->mem_total_mb ?? null) : null);

            if ($value !== null && ($rule === AlertRule::CpuHigh || $row->mem_used_mb !== null)) {
                $values[$server->id][] = $value;
            }
        }

        $out = [];

        foreach ($values as $serverId => $list) {
            $server = $servers[$serverId];
            $settings = $rules[$server->team_id][$rule->value];
            $minutes = $settings->duration ?? 10;
            $threshold = $settings->threshold ?? 90.0;

            if (count($list) < (int) ceil($minutes * 0.6)) {
                continue;
            }

            $average = array_sum($list) / count($list);
            $key = $server->team_id.'|server:'.$server->public_id;

            if ($average < (isset($firing[$key]) ? $threshold - 10 : $threshold)) {
                continue;
            }

            $out[$key] = function () use ($server, $rule, $average, $minutes): AlertSignal {
                $what = $rule === AlertRule::CpuHigh ? 'CPU' : 'Memory';

                return new AlertSignal(
                    team: $this->team($server->team_id),
                    rule: $rule,
                    subject: 'server:'.$server->public_id,
                    title: $rule === AlertRule::CpuHigh ? "{$server->name} is busy" : "{$server->name} is low on memory",
                    summary: "{$what} averaged ".$this->percent($average)." over the last {$minutes} minutes. The server page shows what uses it.",
                    href: "/servers/{$server->public_id}",
                    server: $server,
                    facts: [$what => $this->percent($average)],
                );
            };
        }

        return $out;
    }

    /**
     * Servers whose newest disk sample passes the threshold (resolves 5 points lower).
     *
     * @param  array<int, array<string, ResolvedRule>>  $rules
     * @param  array<string, true>  $firing
     * @return array<string, Closure(): AlertSignal>
     */
    private function disks(array $rules, array $firing, CarbonImmutable $now): array
    {
        $servers = $this->activeServers();
        $rows = DB::table('server_metric_samples')
            ->selectRaw('DISTINCT ON (server_id) server_id, disk_used_mb, disk_total_mb')
            ->whereIn('server_id', array_keys($servers))
            ->where('resolution', 1)
            ->where('at', '>=', $now->subMinutes(15)->toIso8601String())
            ->whereNotNull('disk_used_mb')
            ->where('disk_total_mb', '>', 0)
            ->orderBy('server_id')
            ->orderByDesc('at')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $server = $servers[Cast::id($row->server_id ?? null)] ?? null;

            if ($server === null) {
                continue;
            }

            $used = Cast::id($row->disk_used_mb ?? null);
            $total = Cast::id($row->disk_total_mb ?? null);
            $pct = 100 * $used / max(1, $total);
            $threshold = $rules[$server->team_id][AlertRule::DiskFull->value]->threshold ?? 90.0;
            $key = $server->team_id.'|server:'.$server->public_id;

            if ($pct < (isset($firing[$key]) ? $threshold - 5 : $threshold)) {
                continue;
            }

            $out[$key] = fn (): AlertSignal => new AlertSignal(
                team: $this->team($server->team_id),
                rule: AlertRule::DiskFull,
                subject: 'server:'.$server->public_id,
                title: "{$server->name} is running out of disk space",
                summary: $this->gigabytes($used).' of '.$this->gigabytes($total).' used. Old images and build cache are cleaned up automatically; unused volumes and backups on the server are not.',
                severity: $pct >= 95 ? AlertSeverity::Critical : AlertSeverity::Warning,
                href: "/servers/{$server->public_id}",
                server: $server,
                facts: ['Disk' => $this->percent($pct).' full'],
            );
        }

        return $out;
    }

    /**
     * Domains of lasting environments whose certificate expires within the
     * team's threshold (Caddy renews 30 days ahead, so renewal is failing);
     * local `*.localhost` names are left out.
     *
     * @param  array<int, array<string, ResolvedRule>>  $rules
     * @return array<string, Closure(): AlertSignal>
     */
    private function certificates(array $rules, CarbonImmutable $now): array
    {
        $domains = Domain::query()
            ->whereNotNull('cert_expires_at')
            ->where('cert_expires_at', '<', $now->addDays(29))
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at')
                ->whereHas('environment', static fn (Builder $env) => $env->where('kind', '!=', EnvironmentKind::Preview->value)))
            ->with(['serviceInstance.service', 'serviceInstance.environment.project'])
            ->get();

        $out = [];

        foreach ($domains as $domain) {
            // *.localhost is served by Caddy's internal CA, whose certificates
            // live 12 hours by design: never an expiry problem.
            if (Hostname::isLocal($domain->hostname)) {
                continue;
            }

            $expires = CarbonImmutable::instance($domain->cert_expires_at ?? $now);
            $threshold = $rules[$domain->team_id][AlertRule::CertificateExpiring->value]->threshold ?? 14.0;

            if ($expires->greaterThanOrEqualTo($now->addSeconds((int) round($threshold * 86_400)))) {
                continue;
            }

            $out[$domain->team_id.'|domain:'.$domain->public_id] = function () use ($domain, $expires, $now): AlertSignal {
                $instance = $domain->serviceInstance;
                $environment = $instance->environment;
                $days = (int) floor($now->diffInSeconds($expires, false) / 86_400);
                $when = $expires->lessThanOrEqualTo($now) ? 'expired on '.$expires->format('j M Y') : 'expires on '.$expires->format('j M Y').($days >= 1 ? " ({$days} ".($days === 1 ? 'day' : 'days').')' : ' (today)');
                $facts = ['Expires' => $expires->format('j M Y')];

                if (is_string($domain->cert_error) && $domain->cert_error !== '') {
                    $facts['Last error'] = mb_substr($domain->cert_error, 0, 200);
                }

                return new AlertSignal(
                    team: $this->team($domain->team_id),
                    rule: AlertRule::CertificateExpiring,
                    subject: 'domain:'.$domain->public_id,
                    title: "The certificate for {$domain->hostname} is about to expire",
                    summary: "It {$when} and renewing it keeps failing. Check that the domain still points at your servers.",
                    severity: $expires->lessThan($now->addDays(3)) ? AlertSeverity::Critical : AlertSeverity::Warning,
                    href: "/p/{$environment->project->public_id}/{$environment->public_id}/s/{$instance->service->public_id}/settings",
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $facts,
                );
            };
        }

        return $out;
    }

    /**
     * Servers that report (online or just unreachable), by id.
     *
     * @return array<int, Server>
     */
    private function activeServers(): array
    {
        $out = [];

        foreach (Server::query()->whereIn('status', [ServerStatus::Online->value, ServerStatus::Unreachable->value])->get() as $server) {
            $out[$server->id] = $server;
        }

        return $out;
    }

    private function team(int $id): Team
    {
        return Team::query()->whereKey($id)->firstOrFail();
    }

    private function percent(float $value): string
    {
        return ($value >= 10 ? (string) round($value) : number_format($value, 1)).'%';
    }

    private function gigabytes(int $mb): string
    {
        $gb = $mb / 1024;

        return ($gb >= 10 ? (string) round($gb) : number_format($gb, 1)).' GB';
    }
}

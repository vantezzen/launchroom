<?php

declare(strict_types=1);

namespace App\Services\Alerts\Conditions;

use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertKind;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\ServiceInstance;
use App\Support\Links;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Services whose live deployment has a replica down, unhealthy or
 * crash-looping, for UNHEALTHY_MINUTES (firing ones until they've been
 * healthy that long). Previews never alert; replicas on unreachable or
 * offline servers are the server's alert, not the service's.
 *
 * Time-based state (how long a service has been unhealthy, restart counts)
 * lives in the cache for ≤ 15 minutes; losing it only delays an alert by a
 * few minutes.
 */
final readonly class ServiceUnhealthy extends AlertCondition
{
    public const int UNHEALTHY_MINUTES = 3;

    /** Restarts within CRASH_WINDOW_MINUTES that count as crash-looping. */
    public const int CRASH_RESTARTS = 3;

    public const int CRASH_WINDOW_MINUTES = 10;

    public function __construct(
        private Cache $cache,
    ) {}

    public function rule(): AlertRule
    {
        return AlertRule::ServiceUnhealthy;
    }

    public function resolvedReason(): string
    {
        return 'Every replica is healthy again.';
    }

    public function failing(array $rules, array $firing, CarbonImmutable $now): array
    {
        $rows = DB::table('placements as p')
            ->join('service_instances as si', static fn (JoinClause $join): JoinClause => $join->on('si.id', '=', 'p.service_instance_id')->on('si.active_deployment_id', '=', 'p.deployment_id'))
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('servers as s', 's.id', '=', 'p.server_id')
            ->whereNull('si.removed_at')
            // A stopped service is down on purpose (D68).
            ->whereNull('si.stopped_at')
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

            $out[$key] = function () use ($instance, $state, $now): AlertSignal {
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
                            : "{$state['down']} of {$state['total']} replicas are down. The others keep serving."),
                    severity: $environment->kind === EnvironmentKind::Production ? AlertSeverity::Critical : AlertSeverity::Warning,
                    // Lifecycle events (exits, OOM, failed health checks) are runtime log lines too.
                    href: Links::serviceLogs($instance, 'level:error', $now->subMinutes(self::CRASH_WINDOW_MINUTES), $now),
                    project: $environment->project,
                    environment: $environment,
                    serviceInstance: $instance,
                    facts: $facts,
                    kind: $crashing ? AlertKind::Restarting : AlertKind::Unhealthy,
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
        }

        $this->cache->put($key, $start, 15 * 60);

        return $now->getTimestamp() - $start;
    }
}

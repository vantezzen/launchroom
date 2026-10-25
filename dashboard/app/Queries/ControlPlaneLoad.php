<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Servers\ControlPlaneLoadData;
use App\Models\Server;
use App\Models\User;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Whether the server running launchroom is under heavy load (docs/08 §4),
 * from its per-minute host samples (Report.metrics, D40): CPU averaging at
 * least 90 % or memory at least 95 % over the last 5 minutes, with at least
 * 3 minutes reported. The flag is cached for a minute, so the shared prop
 * costs one cache read per page.
 */
final readonly class ControlPlaneLoad
{
    public const int WINDOW_MINUTES = 5;

    public const int MIN_SAMPLES = 3;

    public const float CPU_THRESHOLD = 90.0;

    public const float MEMORY_THRESHOLD = 95.0;

    private const string CACHE_KEY = 'launchroom:control-plane-load:v1';

    private const int CACHE_SECONDS = 60;

    public function __construct(private Cache $cache) {}

    /**
     * The notice for this user: null unless the control plane is busy and
     * the user may manage its server.
     */
    public function forUser(mixed $user): ?ControlPlaneLoadData
    {
        if (! $user instanceof User) {
            return null;
        }

        $load = $this->current();

        if ($load === null) {
            return null;
        }

        $server = Server::query()->find($load['server']);

        if ($server === null || Gate::forUser($user)->denies('update', $server)) {
            return null;
        }

        return new ControlPlaneLoadData($server->public_id, $server->name, $load['cpu'], $load['memory']);
    }

    /**
     * @return array{server: int, cpu: float|null, memory: float|null}|null
     */
    public function current(?CarbonImmutable $now = null): ?array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && array_key_exists('load', $cached)) {
            return $this->fromCache($cached['load']);
        }

        $load = $this->compute($now ?? CarbonImmutable::now());
        $this->cache->put(self::CACHE_KEY, ['load' => $load], self::CACHE_SECONDS);

        return $load;
    }

    /**
     * @return array{server: int, cpu: float|null, memory: float|null}|null
     */
    public function compute(CarbonImmutable $now): ?array
    {
        $serverId = Server::query()
            ->where('is_control_plane', true)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->value('id');

        if ($serverId === null) {
            return null;
        }

        $rows = DB::table('server_metric_samples')
            ->where('server_id', $serverId)
            ->where('resolution', 1)
            ->where('at', '>', $now->subMinutes(self::WINDOW_MINUTES)->toIso8601String())
            ->where('at', '<=', $now->toIso8601String())
            ->get(['cpu_pct', 'mem_used_mb', 'mem_total_mb']);

        $cpu = [];
        $memory = [];

        foreach ($rows as $row) {
            $value = Cast::float($row->cpu_pct ?? null);

            if ($value !== null) {
                $cpu[] = $value;
            }

            $used = Cast::float($row->mem_used_mb ?? null);
            $total = Cast::float($row->mem_total_mb ?? null);

            if ($used !== null && $total !== null && $total > 0) {
                $memory[] = 100 * $used / $total;
            }
        }

        $cpuAverage = count($cpu) >= self::MIN_SAMPLES ? array_sum($cpu) / count($cpu) : null;
        $memoryAverage = count($memory) >= self::MIN_SAMPLES ? array_sum($memory) / count($memory) : null;

        if (($cpuAverage ?? 0) < self::CPU_THRESHOLD && ($memoryAverage ?? 0) < self::MEMORY_THRESHOLD) {
            return null;
        }

        return [
            'server' => Cast::id($serverId),
            'cpu' => Cast::round($cpuAverage, 1),
            'memory' => Cast::round($memoryAverage, 1),
        ];
    }

    /**
     * @return array{server: int, cpu: float|null, memory: float|null}|null
     */
    private function fromCache(mixed $load): ?array
    {
        if (! is_array($load) || ! isset($load['server'])) {
            return null;
        }

        return [
            'server' => Cast::id($load['server']),
            'cpu' => Cast::float($load['cpu'] ?? null),
            'memory' => Cast::float($load['memory'] ?? null),
        ];
    }
}

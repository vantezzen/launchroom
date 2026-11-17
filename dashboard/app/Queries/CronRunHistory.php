<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Services\CronRunData;
use App\Models\ServiceInstance;
use App\Support\Deployments\ExitMeaning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A cron service's recent runs, newest first (G19), for its panel. The
 * caller authorizes viewing the service.
 */
final readonly class CronRunHistory
{
    public const int LIMIT = 20;

    /**
     * @return list<CronRunData>
     */
    public function for(ServiceInstance $instance, int $limit = self::LIMIT): array
    {
        $rows = DB::table('cron_runs')
            ->where('team_id', $instance->team_id)
            ->where('service_instance_id', $instance->id)
            ->orderByRaw('coalesce(started_at, finished_at) desc')
            ->limit($limit)
            ->get(['started_at', 'finished_at', 'exit_code', 'oom_killed']);
        $out = [];

        foreach ($rows as $row) {
            $code = is_numeric($row->exit_code ?? null) ? (int) $row->exit_code : null;
            $oom = (bool) ($row->oom_killed ?? false);
            $finished = is_string($row->finished_at ?? null) ? CarbonImmutable::parse($row->finished_at)->toIso8601ZuluString() : null;
            $out[] = new CronRunData(
                startedAt: is_string($row->started_at ?? null) ? CarbonImmutable::parse($row->started_at)->toIso8601ZuluString() : null,
                finishedAt: $finished,
                exitCode: $code,
                oomKilled: $oom,
                running: $finished === null,
                failed: $finished !== null && ($oom || ($code !== null && $code !== 0)),
                exitMeaning: $finished !== null ? ExitMeaning::of($code, $oom) : null,
            );
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Logs\RuntimeLogRetentionData;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Models\Environment;
use App\Models\Placement;
use Illuminate\Support\Facades\DB;

/**
 * Turns runtime log retention of an environment on or off, or changes its
 * period and size cap (docs/08 §12). Applies right away: the servers running
 * the environment's workloads get a new desired state with `shipLogs` (no
 * container restarts, docs/03 §5); PruneRuntimeLogs enforces period and cap.
 * Turning it off stops shipping; kept lines then age out like live-tail lines.
 */
final readonly class UpdateRuntimeLogRetention
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, RuntimeLogRetentionData $data, AuditActor $actor): Environment
    {
        $servers = [];

        $environment = DB::transaction(function () use ($environment, $data, $actor, &$servers): Environment {
            $environment = Environment::query()->whereKey($environment->id)->lockForUpdate()->firstOrFail();
            $before = [$environment->runtime_log_retention_days, $environment->runtime_log_max_mb];

            $environment->runtime_log_retention_days = $data->enabled ? $data->days : null;
            $environment->runtime_log_max_mb = $data->enabled ? $data->maxMb : null;

            if ($before === [$environment->runtime_log_retention_days, $environment->runtime_log_max_mb]) {
                return $environment;
            }

            $environment->save();

            /** @var list<int> $servers */
            $servers = Placement::query()
                ->where('team_id', $environment->team_id)
                ->whereIn('service_instance_id', $environment->serviceInstances()->select('id'))
                ->distinct()
                ->pluck('server_id')
                ->all();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::EnvironmentUpdated,
                actor: $actor,
                teamId: $environment->team_id,
                subjectType: 'environment',
                subjectId: $environment->public_id,
                meta: [
                    'runtimeLogs' => $data->enabled ? 'on' : 'off',
                    'runtimeLogDays' => $environment->runtime_log_retention_days,
                    'runtimeLogMaxMb' => $environment->runtime_log_max_mb,
                ],
            ));

            return $environment;
        });

        if ($servers !== []) {
            event(new NodeStateInvalidated($servers));
        }

        return $environment;
    }
}

<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Logs\EnvironmentLogRetentionData;
use App\Enums\EnvironmentKind;
use App\Models\Environment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Project settings → Logs (docs/08 §12): runtime log retention of each
 * environment and what is stored now. Callers authorize `view` on the project.
 */
final class ProjectLogRetention
{
    /**
     * Persistent environments (previews follow their base's lifetime and
     * keep live tails only) with their retention and what is stored now.
     *
     * @return list<EnvironmentLogRetentionData>
     */
    public function for(Project $project): array
    {
        $environments = $project->environments()
            ->where('kind', '!=', EnvironmentKind::Preview->value)
            ->orderByRaw("case kind when 'production' then 0 else 1 end")
            ->orderBy('name')
            ->get();
        $stored = DB::table('log_chunks')
            ->where('team_id', $project->team_id)
            ->whereIn('environment_id', $environments->modelKeys())
            ->where('runtime', true)
            ->groupBy('environment_id')
            ->get(['environment_id', DB::raw('sum(bytes) as bytes'), DB::raw('min(created_at) as oldest')])
            ->keyBy('environment_id');

        return array_values($environments->map(static function (Environment $environment) use ($stored): EnvironmentLogRetentionData {
            $row = (array) ($stored->get($environment->id) ?? []);

            return new EnvironmentLogRetentionData(
                id: $environment->public_id,
                name: $environment->name,
                kind: $environment->kind,
                enabled: $environment->retainsRuntimeLogs(),
                days: $environment->runtime_log_retention_days ?? config()->integer('launchroom.logs.default_retention_days'),
                maxMb: $environment->runtime_log_max_mb ?? config()->integer('launchroom.logs.default_max_mb'),
                storedBytes: is_numeric($row['bytes'] ?? null) ? (int) $row['bytes'] : 0,
                oldestAt: is_string($row['oldest'] ?? null) ? CarbonImmutable::parse($row['oldest'])->toIso8601String() : null,
                canUpdate: Gate::allows('update', $environment),
            );
        })->all());
    }
}

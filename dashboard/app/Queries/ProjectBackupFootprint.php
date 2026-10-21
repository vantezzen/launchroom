<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\RestoreRunStatus;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Backups\RestoreRun;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceInstance;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What deleting a project or environment would mean for backups (docs/14
 * §9.13): services with backups (databases and services with volumes; a
 * successful run or an enabled schedule), which get a final backup first,
 * and whether a backup or restore is running right now.
 */
final class ProjectBackupFootprint
{
    /**
     * @return array{servicesWithBackups: int, inFlight: bool}
     */
    public function for(Project $project): array
    {
        return $this->forInstances(array_values(array_map(static fn (ServiceInstance $instance): int => $instance->id, ServiceInstance::query()
            ->whereIn('environment_id', $project->environments()->select('id'))
            ->get(['id'])
            ->all())));
    }

    /**
     * The same for one environment (deleting an environment).
     *
     * @return array{servicesWithBackups: int, inFlight: bool}
     */
    public function forEnvironment(Environment $environment): array
    {
        return $this->forInstances(array_values(array_map(static fn (ServiceInstance $instance): int => $instance->id, ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->get(['id'])
            ->all())));
    }

    /**
     * @param  list<int>  $instanceIds
     * @return array{servicesWithBackups: int, inFlight: bool}
     */
    private function forInstances(array $instanceIds): array
    {
        if ($instanceIds === []) {
            return ['servicesWithBackups' => 0, 'inFlight' => false];
        }

        $withRuns = BackupRun::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->distinct()
            ->get(['service_instance_id'])
            ->map(static fn (BackupRun $run): int => (int) $run->service_instance_id)
            ->all();

        $withSchedules = BackupSchedule::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->where('enabled', true)
            ->get(['service_instance_id'])
            ->map(static fn (BackupSchedule $schedule): int => (int) $schedule->service_instance_id)
            ->all();

        $inFlight = BackupRun::query()
            ->whereIn('service_instance_id', $instanceIds)
            ->whereIn('status', [BackupRunStatus::Pending->value, BackupRunStatus::Running->value])
            ->exists()
            || RestoreRun::query()
                ->where(static fn (Builder $query) => $query->whereIn('source_service_instance_id', $instanceIds)->orWhereIn('target_service_instance_id', $instanceIds))
                ->whereNotIn('status', [RestoreRunStatus::Succeeded->value, RestoreRunStatus::Failed->value])
                ->exists();

        return [
            'servicesWithBackups' => count(array_unique([...$withRuns, ...$withSchedules])),
            'inFlight' => $inFlight,
        ];
    }
}

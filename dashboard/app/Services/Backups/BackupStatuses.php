<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Data\Backups\ServiceBackupStatusData;
use App\Enums\Backups\BackupHealth;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\ServiceKind;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Environment;
use App\Models\ServiceInstance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Last successful backup N hours ago" for databases and services with
 * volumes (docs/14 §3
 * "Visibility"): canvas nodes, the Backups tab and Settings → Backups.
 *
 * For the canvas: `BackupStatuses::forEnvironment($environment)` returns a
 * {@see ServiceBackupStatusData} per database or volume-backed service, keyed by the
 * service's public id (`svc_…`). Two grouped queries per call, no N+1.
 */
final class BackupStatuses
{
    /**
     * @return array<string, ServiceBackupStatusData> service public id => status
     */
    public function forEnvironment(Environment $environment): array
    {
        $instances = ServiceInstance::query()
            ->where('environment_id', $environment->id)
            ->whereNull('removed_at')
            ->where(static fn (Builder $query): Builder => $query
                ->whereHas('service', static fn (Builder $service): Builder => $service->where('kind', ServiceKind::Database->value))
                ->orWhereHas('volumes'))
            ->with('service')
            ->get();

        $statuses = $this->forInstances(array_values($instances->all()), $environment->team_id);
        $out = [];

        foreach ($instances as $instance) {
            $out[$instance->service->public_id] = $statuses[$instance->id];
        }

        return $out;
    }

    public function forInstance(ServiceInstance $instance): ServiceBackupStatusData
    {
        return $this->forInstances([$instance], $instance->team_id)[$instance->id];
    }

    public function forControlPlane(): ServiceBackupStatusData
    {
        $schedule = BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->first();
        $runs = BackupRun::query()->where('subject', BackupSubject::ControlPlane->value);

        return $this->status(
            configured: BackupDestination::forInstance() instanceof BackupDestination,
            schedule: $schedule,
            lastSuccess: $this->time((clone $runs)->where('status', BackupRunStatus::Succeeded->value)->max('finished_at')),
            latest: (clone $runs)->orderByDesc('id')->first(),
        );
    }

    /**
     * @param  list<ServiceInstance>  $instances
     * @return array<int, ServiceBackupStatusData> instance id => status
     */
    public function forInstances(array $instances, int $teamId): array
    {
        $ids = array_map(static fn (ServiceInstance $i): int => $i->id, $instances);

        if ($ids === []) {
            return [];
        }

        $configured = BackupDestination::query()->where('team_id', $teamId)->exists();
        $schedules = BackupSchedule::query()->whereIn('service_instance_id', $ids)->get()->keyBy('service_instance_id');

        /** @var array<int, mixed> $lastSuccess */
        $lastSuccess = BackupRun::query()
            ->whereIn('service_instance_id', $ids)
            ->where('status', BackupRunStatus::Succeeded->value)
            ->groupBy('service_instance_id')
            ->selectRaw('service_instance_id, max(finished_at) as last_success')
            ->pluck('last_success', 'service_instance_id')
            ->all();

        $latestIds = BackupRun::query()
            ->whereIn('service_instance_id', $ids)
            ->groupBy('service_instance_id')
            ->selectRaw('max(id) as id')
            ->pluck('id');
        $latest = BackupRun::query()->whereIn('id', $latestIds)->get()->keyBy('service_instance_id');

        $out = [];

        foreach ($instances as $instance) {
            $schedule = $schedules->get($instance->id);
            $run = $latest->get($instance->id);

            $out[$instance->id] = $this->status(
                configured: $configured,
                schedule: $schedule instanceof BackupSchedule ? $schedule : null,
                lastSuccess: $this->time($lastSuccess[$instance->id] ?? null),
                latest: $run instanceof BackupRun ? $run : null,
            );
        }

        return $out;
    }

    private function status(bool $configured, ?BackupSchedule $schedule, ?CarbonImmutable $lastSuccess, ?BackupRun $latest): ServiceBackupStatusData
    {
        $now = CarbonImmutable::now();
        $next = $schedule?->nextRunAt($now);
        $nextRunAt = $next instanceof \DateTimeInterface ? CarbonImmutable::instance($next) : null;

        $status = match (true) {
            ! $configured && ! $latest instanceof BackupRun => BackupHealth::Unconfigured,
            $latest instanceof BackupRun && ! $latest->status->isTerminal() => BackupHealth::Running,
            $latest instanceof BackupRun && $latest->status === BackupRunStatus::Failed => BackupHealth::Failing,
            $lastSuccess instanceof CarbonImmutable && $lastSuccess->lt($now->subHours(config()->integer('launchroom.backups.stale_after_hours'))) => BackupHealth::Stale,
            $lastSuccess instanceof CarbonImmutable => BackupHealth::Ok,
            default => BackupHealth::None,
        };

        return new ServiceBackupStatusData(
            $status,
            $lastSuccess,
            $nextRunAt,
            $latest?->status === BackupRunStatus::Failed ? $latest->error : null,
        );
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) || $value instanceof \DateTimeInterface ? CarbonImmutable::parse($value) : null;
    }
}

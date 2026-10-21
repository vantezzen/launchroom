<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Backups\RetentionData;
use App\Enums\Backups\BackupSubject;
use App\Enums\EnvironmentKind;
use App\Enums\ServiceKind;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\ServiceInstance;
use App\Services\Backups\BackupSubjects;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Backups are on by default as soon as a destination exists" (docs/14 §3).
 *
 * Creates the default schedules a destination implies, without touching
 * existing (possibly edited) ones:
 * - the instance destination: the control plane every 6 hours;
 * - a team's default destination: every database and every other service
 *   with volumes in a production or persistent environment, daily at a
 *   staggered time (hash of the instance id → minute and hour, docs/14 §7).
 *   Preview environments get none.
 *
 * Idempotent; the scheduler tick calls it without a destination to pick up
 * databases added since.
 */
final class EnsureBackupSchedules
{
    public function handle(?BackupDestination $destination = null): int
    {
        if ($destination instanceof BackupDestination) {
            return $destination->team_id === null ? $this->controlPlane($destination) : $this->databases($destination);
        }

        $created = 0;
        $instance = BackupDestination::forInstance();

        if ($instance instanceof BackupDestination) {
            $created += $this->controlPlane($instance);
        }

        /** @var list<int> $teams */
        $teams = BackupDestination::query()->whereNotNull('team_id')->distinct()->pluck('team_id')->all();

        foreach ($teams as $teamId) {
            $default = BackupDestination::defaultFor($teamId);

            if ($default instanceof BackupDestination) {
                $created += $this->databases($default);
            }
        }

        return $created;
    }

    /**
     * Minute and hour (UTC, 01:00–06:59) derived from a stable id.
     *
     * @return array{int, int}
     */
    private function staggered(string $id): array
    {
        $hash = crc32($id);

        return [$hash % 60, intdiv($hash, 60) % 6 + 1];
    }

    private function controlPlane(BackupDestination $destination): int
    {
        if (BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->exists()) {
            return 0;
        }

        [$minute] = $this->staggered($destination->public_id);

        BackupSchedule::query()->create([
            'team_id' => null,
            'subject' => BackupSubject::ControlPlane,
            'destination_id' => $destination->id,
            'cron' => "{$minute} */6 * * *",
            'retention' => RetentionData::controlPlane(),
        ]);

        return 1;
    }

    private function databases(BackupDestination $destination): int
    {
        if ($destination->team_id === null || ! $destination->is_default) {
            return 0;
        }

        $instances = ServiceInstance::query()
            ->where('team_id', $destination->team_id)
            ->whereNull('removed_at')
            ->where(static fn (Builder $query): Builder => $query
                ->whereHas('service', static fn (Builder $service): Builder => $service->where('kind', ServiceKind::Database->value))
                ->orWhereHas('volumes'))
            ->whereHas('environment', static fn (Builder $query): Builder => $query->whereIn('kind', [EnvironmentKind::Production->value, EnvironmentKind::Persistent->value]))
            ->whereNotIn('id', BackupSchedule::query()->whereNotNull('service_instance_id')->select('service_instance_id'))
            ->with(['environment', 'service', 'volumes'])
            ->get();

        $created = 0;

        foreach ($instances as $instance) {
            $subject = BackupSubjects::for($instance);

            if (! $subject instanceof BackupSubject) {
                continue;
            }

            [$minute, $hour] = $this->staggered($instance->public_id);

            BackupSchedule::query()->create([
                'team_id' => $destination->team_id,
                'subject' => $subject,
                'service_instance_id' => $instance->id,
                'destination_id' => $destination->id,
                'cron' => "{$minute} {$hour} * * *",
                'retention' => match (true) {
                    $subject === BackupSubject::Volume => RetentionData::volume(),
                    $instance->environment->kind === EnvironmentKind::Production => RetentionData::production(),
                    default => RetentionData::persistent(),
                },
            ]);
            $created++;
        }

        return $created;
    }
}

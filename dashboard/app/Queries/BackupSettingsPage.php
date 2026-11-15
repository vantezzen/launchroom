<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Backups\BackupDestinationData;
use App\Data\Backups\BackupRunData;
use App\Data\Backups\BackupScheduleData;
use App\Data\Backups\RecoveryKitStatusData;
use App\Enums\Backups\BackupSubject;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Queries\Backups\BackupStatuses;
use Illuminate\Support\Facades\Gate;

/**
 * Props of Settings → Backups (docs/14 §3, docs/08 §2): the team's destinations and
 * database schedules; for instance owners also the control-plane
 * destination, its schedule and runs, and the recovery kit.
 */
final readonly class BackupSettingsPage
{
    public function __construct(
        private BackupStatuses $statuses,
        private BackupOverview $overview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Team $team, User $user): array
    {
        $gate = Gate::forUser($user);
        $destinations = BackupDestination::query()->where('team_id', $team->id)->withCount('schedules')->orderByDesc('is_default')->orderBy('id')->get();

        $schedules = BackupSchedule::query()
            ->where('team_id', $team->id)
            ->with(['destination', 'serviceInstance.service', 'serviceInstance.environment.project'])
            ->orderBy('id')
            ->get();
        $health = $this->statuses->forInstances(array_values(array_filter($schedules->map(static fn (BackupSchedule $s) => $s->serviceInstance)->all())), $team->id);

        $visible = $schedules->filter(static fn (BackupSchedule $s): bool => $s->serviceInstance !== null && isset($health[$s->serviceInstance->id]));
        $instance = $gate->allows('manageInstance', BackupDestination::class) ? $this->instance() : null;
        $instanceDestination = $instance === null ? null : BackupDestination::forInstance();
        $instanceSchedule = $instance === null ? null : BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->first();

        return [
            'destinations' => array_values($destinations->map(fn (BackupDestination $d): BackupDestinationData => BackupDestinationData::fromDestination(
                $d,
                $this->count($d),
                $gate->allows('update', $d),
            ))->all()),
            'schedules' => array_values($visible
                ->map(fn (BackupSchedule $s): BackupScheduleData => BackupScheduleData::fromSchedule($s, $health[(int) $s->service_instance_id], $gate->allows('update', $s)))
                ->all()),
            'canManage' => $gate->allows('create', [BackupDestination::class, $team]),
            'instance' => $instance,
            // 30-day run strips per schedule and what each destination holds (docs/design/flows.md §14).
            'protection' => $this->overview->protection(array_values(array_filter([...$visible->all(), $instanceSchedule]))),
            'usage' => $this->overview->usage(array_values(array_filter([...$destinations->all(), $instanceDestination]))),
        ];
    }

    private function count(BackupDestination $destination): int
    {
        $count = $destination->getAttribute('schedules_count');

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return array{destination: BackupDestinationData|null, schedule: BackupScheduleData|null, recoveryKit: RecoveryKitStatusData, runs: list<BackupRunData>}
     */
    private function instance(): array
    {
        $destination = BackupDestination::query()->instanceWide()->withCount('schedules')->first();
        $schedule = BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->with('destination')->first();
        $runs = BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)->orderByDesc('id')->limit(10)->get();

        return [
            'destination' => $destination === null ? null : BackupDestinationData::fromDestination($destination, $this->count($destination), true),
            'schedule' => $schedule === null ? null : BackupScheduleData::fromSchedule($schedule, $this->statuses->forControlPlane(), true),
            'recoveryKit' => RecoveryKitStatusData::fromSettings(InstanceSettings::current(), $destination !== null),
            'runs' => array_values($runs->map(BackupRunData::fromRun(...))->all()),
        ];
    }
}

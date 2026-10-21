<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backups;

use App\Data\Backups\BackupDestinationData;
use App\Data\Backups\BackupRunData;
use App\Data\Backups\BackupScheduleData;
use App\Data\Backups\RecoveryKitStatusData;
use App\Enums\Backups\BackupSubject;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Queries\BackupOverview;
use App\Services\Backups\BackupStatuses;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Backups (docs/14 §3, docs/08 §2): the team's destinations and
 * database schedules; for instance owners also the control-plane
 * destination, its schedule and runs, and the recovery kit.
 */
final class BackupSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user, BackupStatuses $statuses, BackupOverview $overview): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('viewAny', [BackupDestination::class, $team]);

        $destinations = BackupDestination::query()->where('team_id', $team->id)->withCount('schedules')->orderByDesc('is_default')->orderBy('id')->get();

        $schedules = BackupSchedule::query()
            ->where('team_id', $team->id)
            ->with(['destination', 'serviceInstance.service', 'serviceInstance.environment.project'])
            ->orderBy('id')
            ->get();
        $health = $statuses->forInstances(array_values(array_filter($schedules->map(static fn (BackupSchedule $s) => $s->serviceInstance)->all())), $team->id);

        $visible = $schedules->filter(static fn (BackupSchedule $s): bool => $s->serviceInstance !== null && isset($health[$s->serviceInstance->id]));
        $instance = Gate::allows('manageInstance', BackupDestination::class) ? $this->instance($statuses) : null;
        $instanceDestination = $instance === null ? null : BackupDestination::query()->whereNull('team_id')->first();
        $instanceSchedule = $instance === null ? null : BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->first();

        return Inertia::render('settings/backups', [
            'destinations' => array_values($destinations->map(fn (BackupDestination $d): BackupDestinationData => BackupDestinationData::fromDestination(
                $d,
                $this->count($d),
                Gate::allows('update', $d),
            ))->all()),
            'schedules' => array_values($visible
                ->map(fn (BackupSchedule $s): BackupScheduleData => BackupScheduleData::fromSchedule($s, $health[(int) $s->service_instance_id], Gate::allows('update', $s)))
                ->all()),
            'canManage' => Gate::allows('create', [BackupDestination::class, $team]),
            'instance' => $instance,
            // 30-day run strips per schedule and what each destination holds (docs/design/flows.md §14).
            'protection' => $overview->protection(array_values(array_filter([...$visible->all(), $instanceSchedule]))),
            'usage' => $overview->usage(array_values(array_filter([...$destinations->all(), $instanceDestination]))),
        ]);
    }

    private function count(BackupDestination $destination): int
    {
        $count = $destination->getAttribute('schedules_count');

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return array{destination: BackupDestinationData|null, schedule: BackupScheduleData|null, recoveryKit: RecoveryKitStatusData, runs: list<BackupRunData>}
     */
    private function instance(BackupStatuses $statuses): array
    {
        $destination = BackupDestination::query()->whereNull('team_id')->withCount('schedules')->first();
        $schedule = BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->with('destination')->first();
        $runs = BackupRun::query()->where('subject', BackupSubject::ControlPlane->value)->orderByDesc('id')->limit(10)->get();

        return [
            'destination' => $destination === null ? null : BackupDestinationData::fromDestination($destination, $this->count($destination), true),
            'schedule' => $schedule === null ? null : BackupScheduleData::fromSchedule($schedule, $statuses->forControlPlane(), true),
            'recoveryKit' => RecoveryKitStatusData::fromSettings(InstanceSettings::current(), $destination !== null),
            'runs' => array_values($runs->map(BackupRunData::fromRun(...))->all()),
        ];
    }
}

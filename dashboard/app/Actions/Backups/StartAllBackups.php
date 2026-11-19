<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Data\Backups\BackupBatch;
use App\Enums\Backups\BackupReason;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Models\Backups\BackupSchedule;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Backups\BackupBatches;
use App\Support\PublicId;
use App\Support\Refusal;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * "Back up everything now" (docs/14 §3): one manual backup of every item
 * the team's schedules protect (each database, each backed-up volume), and
 * launchroom itself when the user is an instance owner and its setup is
 * complete. Nothing waits here: launchroomd runs one heavy task per server
 * (docs/14 §7), so runs on the same server queue there and the page shows
 * them as waiting for that server.
 *
 * Items already backing up count as such (their run is not restarted);
 * items that can't start (stopped, never deployed, server offline) are
 * listed with the reason and don't stop the others. The runs share a batch
 * id; the newest batch per team is kept for the page's progress line
 * (BackupBatches).
 */
final readonly class StartAllBackups
{
    public function __construct(
        private StartServiceBackup $service,
        private StartControlPlaneBackup $controlPlane,
    ) {}

    /**
     * @throws DomainRuleViolation when there is nothing to back up
     */
    public function handle(Team $team, User $user, AuditActor $actor): BackupBatch
    {
        $batch = PublicId::generate('bkb');
        $started = 0;
        $already = 0;
        $refused = [];

        $schedules = BackupSchedule::query()
            ->where('team_id', $team->id)
            ->whereNotNull('service_instance_id')
            ->whereIn('destination_id', BackupDestination::query()->select('id'))
            ->whereHas('serviceInstance', static fn (Builder $query) => $query->whereNull('removed_at'))
            ->with(['serviceInstance.service', 'serviceInstance.volumes'])
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            $instance = $schedule->serviceInstance;

            if ($instance === null) {
                continue;
            }

            if ($instance->stopped_at !== null) {
                $refused[] = ['name' => $instance->service->name, 'message' => "{$instance->service->name} is stopped. Start it to back it up."];

                continue;
            }

            $runs = $this->service->handle($instance, BackupReason::Manual, $user, $actor, $schedule, $batch);

            if ($runs instanceof Refusal) {
                $refused[] = ['name' => $instance->service->name, 'message' => $runs->message];

                continue;
            }

            foreach ($runs as $run) {
                if ($run->wasRecentlyCreated) {
                    $started++;
                } else {
                    $already++;
                }
            }
        }

        if ($user->is_instance_owner && BackupDestination::forInstance() instanceof BackupDestination && InstanceSettings::current()->recovery_public_key !== null) {
            $run = $this->controlPlane->handle(BackupReason::Manual, $user, $actor, batch: $batch);

            if ($run->wasRecentlyCreated) {
                $started++;
            } else {
                $already++;
            }
        }

        if ($started === 0 && $already === 0 && $refused === []) {
            throw DomainRuleViolation::on('everything', 'Nothing is backed up yet: connect backup storage first.');
        }

        $result = new BackupBatch($batch, $started, $already, $refused);
        BackupBatches::remember($team, $batch, $refused);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Protocol\V1\WorkloadEvent;
use App\Enums\ServiceKind;
use App\Enums\WorkloadEventKind;
use App\Enums\WorkloadFailureCode;
use App\Models\Server;
use App\Support\Metrics\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns launchroomd's lifecycle events (Report.events, D68) into two
 * histories that alerts and pages read:
 *
 * - `cron_runs`: a scheduled cron's `started` opens a run, its `exited`
 *   closes the newest open one with the exit code (docs/04 §1 "Cron
 *   schedules"); `cron_failed` reads them (G19);
 * - `service_crashes`: an `exited` with an error of a replica of the live
 *   deployment that launchroom didn't stop itself; `service_crashed` and the
 *   panel's "restarted 3 times today" read them (G7).
 *
 * A server only writes about its own placements. Re-sent events are stored
 * once (same workload and time).
 */
final readonly class RecordWorkloadRuns
{
    /**
     * @param  list<WorkloadEvent>  $events
     */
    public function handle(Server $server, array $events): void
    {
        $relevant = array_values(array_filter($events, static fn (WorkloadEvent $event): bool => in_array($event->kind, [WorkloadEventKind::Started, WorkloadEventKind::Exited], true)));

        if ($relevant === []) {
            return;
        }

        $placements = DB::table('placements as p')
            ->join('service_instances as si', 'si.id', '=', 'p.service_instance_id')
            ->join('services as s', 's.id', '=', 'si.service_id')
            ->where('p.server_id', $server->id)
            ->whereIn('p.workload_id', array_values(array_unique(array_map(static fn (WorkloadEvent $event): string => $event->workload, $relevant))))
            ->get(['p.workload_id', 'p.service_instance_id', 'p.deployment_id', 'si.team_id', 'si.active_deployment_id', 's.kind'])
            ->keyBy('workload_id');

        foreach ($relevant as $event) {
            $placement = $placements->get($event->workload);

            if ($placement === null || Cast::id($placement->team_id ?? null) !== $server->team_id) {
                continue;
            }

            $row = [
                'team_id' => $server->team_id,
                'service_instance_id' => Cast::id($placement->service_instance_id ?? null),
                'deployment_id' => Cast::id($placement->deployment_id ?? null),
                'server_id' => $server->id,
                'workload_id' => $event->workload,
            ];
            $at = CarbonImmutable::parse($event->at)->utc();

            if (($placement->kind ?? null) === ServiceKind::Cron->value) {
                $this->cron($event, $row, $at);

                continue;
            }

            $live = Cast::id($placement->active_deployment_id ?? null) === $row['deployment_id'];

            if ($live && $this->crashed($event)) {
                DB::table('service_crashes')->insertOrIgnore([...$row, 'at' => $at,
                    'exit_code' => is_int($event->exitCode) ? $event->exitCode : null,
                    'signal' => is_int($event->signal) ? $event->signal : null,
                    'oom_killed' => $event->oomKilled === true,
                    'code' => $event->code instanceof WorkloadFailureCode ? $event->code->value : null,
                ]);
            }
        }
    }

    /**
     * An exit with an error that launchroom didn't cause (a stop, a rollout).
     */
    private function crashed(WorkloadEvent $event): bool
    {
        if ($event->kind !== WorkloadEventKind::Exited || $event->byLaunchroom === true) {
            return false;
        }

        return $event->oomKilled === true || (is_int($event->exitCode) && $event->exitCode !== 0);
    }

    /**
     * @param  array{team_id: int, service_instance_id: int, deployment_id: int, server_id: int, workload_id: string}  $row
     */
    private function cron(WorkloadEvent $event, array $row, CarbonImmutable $at): void
    {
        $runs = DB::table('cron_runs')->where('workload_id', $row['workload_id']);

        if ($event->kind === WorkloadEventKind::Started) {
            if (! (clone $runs)->where('started_at', $at)->exists()) {
                DB::table('cron_runs')->insert([...$row, 'started_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
            }

            return;
        }

        if ($event->byLaunchroom === true || (clone $runs)->where('finished_at', $at)->exists()) {
            return;
        }

        $finished = [
            'finished_at' => $at,
            'exit_code' => is_int($event->exitCode) ? $event->exitCode : null,
            'oom_killed' => $event->oomKilled === true,
            'updated_at' => now(),
        ];
        $open = (clone $runs)->whereNull('finished_at')->where('started_at', '<=', $at)->latest('started_at')->value('id');

        if ($open !== null) {
            DB::table('cron_runs')->where('id', $open)->update($finished);

            return;
        }

        // The start was never reported (launchroomd restarted in between).
        DB::table('cron_runs')->insert([...$row, ...$finished, 'created_at' => now()]);
    }
}

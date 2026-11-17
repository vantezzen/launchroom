<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Data\Logs\LogTailStartedData;
use App\Data\Protocol\V1\Tasks\LogsSpec;
use App\Enums\DaemonTaskPurpose;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\LogTails;
use App\Services\Tasks\DaemonTasks;
use App\Services\Tasks\TaskCancellation;
use App\Support\Deployments\ExitMeaning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Starts (or renews) live runtime logs for the log explorer
 * (direction.md §9.8): one `logs` task with follow per running or exited
 * workload of the environment's live deployments (optionally one service),
 * pinned to the server that runs it. launchroomd follows a container across
 * exits and restarts, and ships an exited container's history, then waits
 * for its next start (G9). Lines arrive as task chunks, masked against the
 * deployment's secrets like every deployment log (StoreLogBatch).
 *
 * Renewing keeps tails that are younger than half their lifetime and
 * replaces older ones with a tail that starts now (no repeated history), so
 * an open explorer never runs out of lines. Returns the number of workloads
 * followed and, per replica, why it has no live tail (its server is
 * offline, it never started) or isn't running (it exited).
 */
final readonly class StartLogTail
{
    /** Past lines a new tail starts with. */
    private const int HISTORY = 200;

    public function __construct(
        private TaskCancellation $cancellation,
        private DaemonTasks $tasks,
    ) {}

    public function handle(Environment $environment, User $user, ?ServiceInstance $only = null): LogTailStartedData
    {
        return DB::transaction(function () use ($environment, $user, $only): LogTailStartedData {
            $placements = Placement::query()
                ->where('team_id', $environment->team_id)
                ->where('phase', '!=', WorkloadPhase::Removed->value)
                ->whereIn('service_instance_id', ServiceInstance::query()
                    ->select('id')
                    ->where('environment_id', $environment->id)
                    ->when($only instanceof ServiceInstance, static fn ($query) => $query->whereKey($only?->id)))
                ->with(['serviceInstance:id,active_deployment_id,service_id', 'serviceInstance.service:id,name', 'server'])
                ->orderBy('id')
                ->limit(50)
                ->get()
                // Only the live version's replicas (not a draining or staged one).
                ->filter(static fn (Placement $placement): bool => $placement->deployment_id === $placement->serviceInstance->active_deployment_id);

            $notes = [];
            $followable = [];

            foreach ($placements as $placement) {
                $note = $this->note($placement);

                if ($note !== null) {
                    $notes[] = $note;
                }

                if ($placement->server->status === ServerStatus::Online
                    && (in_array($placement->phase, WorkloadPhase::running(), true) || $placement->phase === WorkloadPhase::Exited)) {
                    $followable[] = $placement;
                }
            }

            $open = LogTails::open($environment, $user)->lockForUpdate()->get();
            $fresh = CarbonImmutable::now()->subSeconds(intdiv(LogTails::SECONDS, 2));
            $followed = 0;

            foreach ($followable as $placement) {
                $existing = $open->filter(static fn (DaemonTask $task): bool => ($task->spec->workload ?? null) === $placement->workload_id);
                $keep = $existing->first(static fn (DaemonTask $task): bool => $task->created_at !== null && $task->created_at->greaterThan($fresh));

                foreach ($existing as $task) {
                    if ($task->id !== $keep?->id) {
                        $this->cancellation->cancel($task);
                    }
                }

                $followed++;

                if ($keep instanceof DaemonTask) {
                    continue;
                }

                $this->tasks->create(
                    $environment->team_id,
                    new LogsSpec(
                        workload: $placement->workload_id,
                        tail: $existing->isEmpty() ? self::HISTORY : 0,
                        follow: true,
                        since: $existing->isEmpty() ? null : CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ),
                    LogTails::SECONDS,
                    pin: $placement->server,
                    purpose: DaemonTaskPurpose::LogTail,
                    deploymentId: $placement->deployment_id,
                    requestedById: $user->id,
                );
            }

            return new LogTailStartedData($followed, LogTails::RENEW_SECONDS, $notes);
        });
    }

    /**
     * Why a replica has no live tail, or what a followed one is doing
     * ("server-2 is offline, …", "Replica 2 of api isn't running: …").
     */
    private function note(Placement $placement): ?string
    {
        $replica = 'replica '.($placement->slot + 1).' of '.$placement->serviceInstance->service->name;
        $server = $placement->server;

        if ($server->status !== ServerStatus::Online) {
            $state = $server->status === ServerStatus::Unreachable ? 'not reachable' : 'offline';

            return "{$server->name} is {$state}, so {$replica} can’t show live logs.";
        }

        $exit = $placement->last_exit;

        return match ($placement->phase) {
            WorkloadPhase::Exited => ucfirst($replica).' isn’t running.'.($exit !== null && $exit->code !== null ? ' '.ExitMeaning::summaryOfExit($exit) : '')
                .' Its last lines are shown, and live logs go on when it starts again.',
            WorkloadPhase::Failed => ucfirst($replica).' couldn’t start, so it has no logs. Its deployment says why.',
            WorkloadPhase::Pending, WorkloadPhase::Pulling => ucfirst($replica).' is still starting. Live logs begin once it runs.',
            default => null,
        };
    }
}

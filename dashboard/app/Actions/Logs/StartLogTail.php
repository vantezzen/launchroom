<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Enums\DaemonTaskType;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Models\DaemonTask;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\LogTails;
use App\Services\Tasks\TaskCancellation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Starts (or renews) live runtime logs for the log explorer
 * (direction.md §9.8): one `logs` task with follow per running workload of
 * the environment's live deployments (optionally one service), pinned to the
 * server that runs it. Lines arrive as task chunks, masked against the
 * deployment's secrets like every deployment log (StoreLogBatch).
 *
 * Renewing keeps tails that are younger than half their lifetime and
 * replaces older ones with a tail that starts now (no repeated history), so
 * an open explorer never runs out of lines. Returns the number of workloads
 * followed.
 */
final readonly class StartLogTail
{
    /** Past lines a new tail starts with. */
    private const int HISTORY = 200;

    public function __construct(
        private TaskCancellation $cancellation,
    ) {}

    public function handle(Environment $environment, User $user, ?ServiceInstance $only = null): int
    {
        return DB::transaction(function () use ($environment, $user, $only): int {
            $placements = Placement::query()
                ->where('team_id', $environment->team_id)
                ->whereIn('phase', [WorkloadPhase::Starting->value, WorkloadPhase::Running->value, WorkloadPhase::Healthy->value, WorkloadPhase::Unhealthy->value])
                ->whereIn('service_instance_id', ServiceInstance::query()
                    ->select('id')
                    ->where('environment_id', $environment->id)
                    ->when($only instanceof ServiceInstance, static fn ($query) => $query->whereKey($only?->id)))
                ->whereHas('server', static fn (Builder $query) => $query->where('status', ServerStatus::Online->value))
                ->with('serviceInstance:id,active_deployment_id')
                ->orderBy('id')
                ->limit(50)
                ->get()
                // Only the live version's replicas (not a draining or staged one).
                ->filter(static fn (Placement $placement): bool => $placement->deployment_id === $placement->serviceInstance->active_deployment_id);

            $open = LogTails::open($environment, $user)->lockForUpdate()->get();
            $fresh = CarbonImmutable::now()->subSeconds(intdiv(LogTails::SECONDS, 2));
            $followed = 0;

            foreach ($placements as $placement) {
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

                DaemonTask::query()->create([
                    'team_id' => $environment->team_id,
                    'server_id' => $placement->server_id,
                    'pinned_to_server' => true,
                    'type' => DaemonTaskType::Logs,
                    'spec' => (object) array_filter([
                        'workload' => $placement->workload_id,
                        'tail' => $existing->isEmpty() ? self::HISTORY : 0,
                        'follow' => true,
                        'since' => $existing->isEmpty() ? null : CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ], static fn (mixed $value): bool => $value !== null),
                    'max_attempts' => 1,
                    'timeout_seconds' => LogTails::SECONDS,
                    'deployment_id' => $placement->deployment_id,
                    'purpose' => LogTails::purpose($user),
                ]);
            }

            return $followed;
        });
    }
}

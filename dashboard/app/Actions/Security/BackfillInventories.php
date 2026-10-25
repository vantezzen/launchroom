<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\EnvironmentKind;
use App\Enums\Security\InventoryStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Security\ImageInventory;
use App\Models\Server;
use App\Services\Security\InventoryTasks;
use App\Support\Metrics\Cast;
use Illuminate\Support\Facades\DB;

/**
 * Keeps inventories complete (docs/22 §3.1), hourly:
 *
 * - live deployments of non-preview environments without an inventory get
 *   one (the update that brings the security center, images whose task was
 *   never created), at most 2 pending inventory tasks per build server of
 *   the team;
 * - a failed inventory is tried again twice: 10 minutes and an hour after
 *   it failed; then it stays failed with its reason.
 */
final readonly class BackfillInventories
{
    public function __construct(
        private RequestInventory $request,
        private InventoryTasks $tasks,
    ) {}

    /**
     * @return array{requested: int, retried: int}
     */
    public function handle(): array
    {
        $requested = 0;

        foreach ($this->missingByTeam() as $teamId => $deploymentIds) {
            $budget = $this->budget($teamId);

            foreach (array_slice($deploymentIds, 0, max(0, $budget)) as $deploymentId) {
                $deployment = Deployment::query()->whereKey($deploymentId)->first();

                if ($deployment instanceof Deployment && $this->request->handle($deployment) instanceof ImageInventory) {
                    $requested++;
                }
            }
        }

        return ['requested' => $requested, 'retried' => $this->retryFailed()];
    }

    /**
     * @return array<int, list<int>> team → live deployments without an inventory
     */
    private function missingByTeam(): array
    {
        $rows = DB::table('service_instances as si')
            ->join('environments as e', 'e.id', '=', 'si.environment_id')
            ->join('deployments as d', 'd.id', '=', 'si.active_deployment_id')
            ->whereNull('si.removed_at')
            ->where('e.kind', '!=', EnvironmentKind::Preview->value)
            ->whereNull('d.inventory_id')
            ->whereNotNull('d.image')
            ->orderBy('d.id')
            ->get(['d.id', 'd.team_id']);

        $out = [];

        foreach ($rows as $row) {
            $out[Cast::id($row->team_id ?? null)][] = Cast::id($row->id ?? null);
        }

        return $out;
    }

    private function budget(int $teamId): int
    {
        $builders = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Build->value)
            ->count();

        $pending = DaemonTask::query()
            ->where('team_id', $teamId)
            ->where('type', DaemonTaskType::Inventory->value)
            ->whereIn('status', [DaemonTaskStatus::Pending->value, DaemonTaskStatus::Leased->value])
            ->count();

        return $builders * config()->integer('launchroom.security.backfill_per_server', 2) - $pending;
    }

    private function retryFailed(): int
    {
        $retried = 0;

        $failed = ImageInventory::query()
            ->where('status', InventoryStatus::Failed->value)
            ->where('retries', '<', 2)
            ->whereNotNull('failed_at')
            ->get();

        foreach ($failed as $inventory) {
            $wait = $inventory->retries === 0 ? 10 : 60;

            if ($inventory->failed_at?->greaterThan(now()->subMinutes($wait)) ?? true) {
                continue;
            }

            $deployment = Deployment::query()
                ->where('team_id', $inventory->team_id)
                ->where('inventory_id', $inventory->id)
                ->with(['environment', 'serviceInstance.service'])
                ->latest('id')
                ->first();
            $spec = $deployment === null ? null : $this->tasks->spec($deployment, $inventory->image, $inventory->platform);

            if ($spec === null) {
                continue;
            }

            DB::transaction(function () use ($inventory, $spec): void {
                $inventory->forceFill([
                    'retries' => $inventory->retries + 1,
                    'error' => null,
                    'task_id' => $this->tasks->create($inventory->team_id, $spec['spec'], $spec['seal'])->id,
                ]);
                $inventory->transitionTo(InventoryStatus::Pending);
            });
            $retried++;
        }

        return $retried;
    }
}

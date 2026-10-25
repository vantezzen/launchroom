<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Protocol\V1\InventoryComponent;
use App\Data\Protocol\V1\InventoryResult;
use App\Enums\Security\AdvisoryFeed;
use App\Enums\Security\InventoryStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Jobs\Security\SyncAdvisoriesJob;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Security\AdvisoryFeedState;
use App\Models\Security\ImageInventory;
use App\Support\Security\OsvPackage;
use Illuminate\Support\Facades\DB;

/**
 * Stores a finished inventory (TaskCompletion.inventory, docs/22 §3.5):
 * the components with their OSV package (null when there is no mapping),
 * the distribution, lockfiles and notes; then links every deployment of the
 * team with that image and queues the advisory check of the new
 * components (CheckInventoryAdvisories). Runs inside CompleteTask's
 * transaction, so a refused result fails the task instead.
 */
final readonly class CompleteInventory
{
    /**
     * @throws DomainRuleViolation when the result doesn't belong to the task
     */
    public function handle(DaemonTask $task, InventoryResult $result): ImageInventory
    {
        $inventory = ImageInventory::query()
            ->where('team_id', $task->team_id)
            ->where('task_id', $task->id)
            ->lockForUpdate()
            ->first();

        if (! $inventory instanceof ImageInventory || $inventory->status !== InventoryStatus::Pending) {
            throw DomainRuleViolation::conflict('No inventory is waiting for this task.');
        }

        if (! hash_equals($inventory->image, $result->image) || $inventory->platform !== $result->platform) {
            throw DomainRuleViolation::conflict('The inventory is for another image or platform than the task asked for.');
        }

        DB::table('inventory_components')->where('inventory_id', $inventory->id)->delete();
        $rows = [];
        $unqueried = 0;

        foreach ($result->components as $component) {
            $osv = OsvPackage::for($component->ecosystem, $component->name, $component->version, $component->purl, $result->distro);
            $unqueried += $osv === null ? 1 : 0;
            $rows[] = $this->row($inventory, $component, $osv);
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('inventory_components')->insert($chunk);
        }

        $inventory->forceFill([
            'generator' => $result->generator,
            'source_analyzed' => $result->sourceAnalyzed,
            'distro' => $result->distro,
            'lockfiles' => $result->lockfiles,
            'notes' => $result->notes,
            'component_count' => count($rows),
            'unqueried_count' => $unqueried,
            'dropped' => $result->dropped,
            'truncated' => $result->truncated,
            'error' => null,
            'scanned_at' => now(),
        ]);
        $inventory->transitionTo(InventoryStatus::Ready);

        Deployment::query()
            ->where('team_id', $inventory->team_id)
            ->where('image', $inventory->image)
            ->whereNull('inventory_id')
            ->update(['inventory_id' => $inventory->id]);

        // CheckInventoryAdvisories (docs/22 §4.3); without OSV only the inventory changes.
        if (AdvisoryFeedState::enabled(AdvisoryFeed::Osv)) {
            dispatch(new SyncAdvisoriesJob($inventory->id))->afterCommit();
        } else {
            dispatch(new EvaluateSecurityFindingsJob($inventory->team_id))->afterCommit();
        }

        return $inventory;
    }

    /**
     * @param  array{ecosystem: string, name: string, version: string}|null  $osv
     * @return array<string, mixed>
     */
    private function row(ImageInventory $inventory, InventoryComponent $component, ?array $osv): array
    {
        return [
            'inventory_id' => $inventory->id,
            'ecosystem' => $component->ecosystem,
            'name' => $component->name,
            'version' => $component->version,
            'purl' => $component->purl,
            'kind' => $component->kind,
            'scope' => $component->scope,
            'presence' => $component->presence,
            'direct' => $component->direct,
            'osv_ecosystem' => $osv['ecosystem'] ?? null,
            'osv_name' => $osv === null ? null : mb_substr($osv['name'], 0, 256),
            'osv_version' => $osv === null ? null : mb_substr($osv['version'], 0, 128),
        ];
    }
}

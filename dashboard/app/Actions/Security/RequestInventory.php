<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\EnvironmentKind;
use App\Models\Deployment;
use App\Models\Security\ImageInventory;
use App\Services\Security\InventoryTasks;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure the image of a live deployment has an inventory in its team
 * (docs/22 §3.1): links the deployment to an existing one, or creates a
 * pending inventory and its `inventory` task. Built images get the build's
 * commit as `source` (lockfiles set scopes; the clone credential is sealed
 * at lease time); image services and engines get none. Previews are not
 * inventoried. Never blocks a deployment: anything that can't become a
 * valid spec is skipped.
 */
final readonly class RequestInventory
{
    private const string DIGEST_REF = '#^[a-z0-9.:-]+(?:/[a-z0-9._-]+)+@sha256:[a-f0-9]{64}$#D';

    public function __construct(
        private InventoryTasks $tasks,
    ) {}

    public function handle(Deployment $deployment): ?ImageInventory
    {
        $deployment->loadMissing(['environment', 'serviceInstance.service']);
        $image = $deployment->image;

        if ($deployment->environment->kind === EnvironmentKind::Preview || ! is_string($image) || strlen($image) > 512 || preg_match(self::DIGEST_REF, $image) !== 1) {
            return null;
        }

        $platform = $this->tasks->platform($deployment);

        if ($platform === null) {
            return null;
        }

        return DB::transaction(function () use ($deployment, $image, $platform): ?ImageInventory {
            $inventory = ImageInventory::query()
                ->where('team_id', $deployment->team_id)
                ->where('image', $image)
                ->where('platform', $platform)
                ->lockForUpdate()
                ->first();

            if ($inventory instanceof ImageInventory) {
                $this->link($deployment, $inventory);

                return $inventory;
            }

            $spec = $this->tasks->spec($deployment, $image, $platform);

            if ($spec === null) {
                return null;
            }

            $inventory = ImageInventory::query()->create([
                'team_id' => $deployment->team_id,
                'image' => $image,
                'platform' => $platform,
                'source' => $spec['source'],
                'service_id' => $spec['built'] ? $deployment->serviceInstance->service_id : null,
            ]);
            $inventory->forceFill(['task_id' => $this->tasks->create($deployment->team_id, $spec['spec'], $spec['seal'])->id])->save();
            $this->link($deployment, $inventory);

            return $inventory;
        });
    }

    private function link(Deployment $deployment, ImageInventory $inventory): void
    {
        Deployment::query()
            ->where('team_id', $deployment->team_id)
            ->where('image', $inventory->image)
            ->whereNull('inventory_id')
            ->update(['inventory_id' => $inventory->id]);
    }
}

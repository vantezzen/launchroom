<?php

declare(strict_types=1);

namespace App\Queries\Security;

use App\Data\Api\InventoryComponentResource;
use App\Data\Api\ServiceInventoryResource;
use App\Models\Security\ImageInventory;
use App\Models\ServiceInstance;
use App\Support\Metrics\Cast;
use Illuminate\Support\Facades\DB;

/**
 * The inventory of a service instance's live image (docs/22 §11).
 */
final readonly class ServiceInventory
{
    public function handle(ServiceInstance $instance): ?ServiceInventoryResource
    {
        $inventoryId = $instance->activeDeployment?->inventory_id;
        $inventory = $inventoryId === null ? null : ImageInventory::query()->where('team_id', $instance->team_id)->whereKey($inventoryId)->first();

        if (! $inventory instanceof ImageInventory) {
            return null;
        }

        $components = DB::table('inventory_components')->where('inventory_id', $inventory->id)
            ->orderByRaw("CASE kind WHEN 'runtime' THEN 0 WHEN 'library' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): InventoryComponentResource => new InventoryComponentResource(
                Cast::string($row->ecosystem ?? null),
                Cast::string($row->name ?? null),
                Cast::string($row->version ?? null),
                is_string($row->purl ?? null) ? $row->purl : null,
                Cast::string($row->kind ?? null),
                Cast::string($row->scope ?? null),
                Cast::string($row->presence ?? null),
                (bool) ($row->direct ?? false),
            ))->values()->all();

        return new ServiceInventoryResource(
            id: $inventory->public_id,
            image: $inventory->image,
            platform: $inventory->platform,
            status: $inventory->status->value,
            source: $inventory->source->value,
            distro: $inventory->distro,
            scannedAt: $inventory->scanned_at?->toIso8601ZuluString(),
            truncated: $inventory->truncated,
            notes: $inventory->notes,
            error: $inventory->error,
            components: array_values($components),
        );
    }
}

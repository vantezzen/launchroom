<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\PlacementIssueKind;
use App\Enums\ServerStatus;
use App\Enums\WorkloadRole;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;

/**
 * Clears the degraded/unplaceable marks EvacuateDeadServer set once they no
 * longer hold (the server is back, or every replica moved).
 */
final readonly class ClearResolvedPlacementIssues
{
    public function handle(): int
    {
        $cleared = 0;

        $instances = ServiceInstance::query()
            ->whereNotNull('placement_issue')
            ->with(['volumes', 'volumeMounts.volume', 'service'])
            ->get();

        foreach ($instances as $instance) {
            $resolved = match ($instance->placement_issue?->kind) {
                PlacementIssueKind::StatefulServerDown => $instance->mountedVolumes()->every(static fn ($volume): bool => $volume->server_id === null)
                    || Server::query()->whereKey($instance->mountedVolumes()->pluck('server_id')->filter()->all())->where('status', '!=', ServerStatus::Online->value)->doesntExist(),
                default => Placement::query()
                    ->where('service_instance_id', $instance->id)
                    ->where('role', WorkloadRole::Serving->value)
                    ->whereIn('server_id', Server::query()->select('id')->where('status', '!=', ServerStatus::Online->value))
                    ->doesntExist(),
            };

            if ($resolved) {
                $instance->forceFill(['placement_issue' => null])->save();
                $cleared++;
            }
        }

        return $cleared;
    }
}

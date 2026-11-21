<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\ServiceStatusChangedData;
use App\Models\ServiceInstance;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The status of some services of an environment may have changed without
 * anything else on its canvas changing (D108, docs/08 §23): a container
 * started, exited or turned unhealthy, its server stopped reporting, a hold
 * started or ended. A nudge only: viewers refetch the environment's live
 * status through `projects.live-status`, which re-checks authorization.
 */
final readonly class ServiceStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public string $environmentId,
        public array $serviceIds,
    ) {}

    /**
     * One nudge per environment of these service instances.
     *
     * @param  iterable<int>  $instanceIds
     */
    public static function forInstances(iterable $instanceIds): void
    {
        $ids = [...$instanceIds];

        if ($ids === []) {
            return;
        }

        $byEnvironment = [];

        foreach (ServiceInstance::query()->whereKey(array_values(array_unique($ids)))->with(['environment:id,public_id', 'service:id,public_id'])->get() as $instance) {
            $byEnvironment[$instance->environment->public_id][] = $instance->service->public_id;
        }

        foreach ($byEnvironment as $environment => $services) {
            event(new self($environment, array_values(array_unique($services))));
        }
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [Channel::Environment->private($this->environmentId)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::ServiceStatus->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new ServiceStatusChangedData($this->serviceIds)->toArray();
    }
}

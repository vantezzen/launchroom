<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge: a deployment changed status (docs/08 §4 "realtime by
 * default"). Carries ids and the status only; clients refetch what they show
 * through authorized endpoints. Channels are authorized in routes/channels.php.
 */
final readonly class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $deploymentId,
        public string $environmentId,
        public string $serviceId,
        public string $status,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("environments.{$this->environmentId}"),
            new PrivateChannel("deployments.{$this->deploymentId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.updated';
    }
}

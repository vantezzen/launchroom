<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge about a deployment (docs/08 §4 "realtime by default").
 * Carries ids and the status only; clients refetch what they show through
 * authorized endpoints. Channels are authorized in routes/channels.php.
 * `statusChanged` is true only when the status itself just changed: other
 * nudges (pull progress, a build being picked up) resend the current status,
 * and outcome toasts must not announce those again.
 */
final readonly class DeploymentUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $deploymentId,
        public string $environmentId,
        public string $serviceId,
        public string $status,
        public bool $statusChanged = false,
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

<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\LogsAppendedData;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge: new log lines were stored for a deployment. One per daemon
 * log batch (daemons ship at most every second); clients coalesce nudges and
 * fetch the lines after their cursor at most every 200 ms.
 */
final readonly class DeploymentLogsAppended implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $deploymentId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [Channel::Deployment->private($this->deploymentId)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::LogsAppended->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new LogsAppendedData($this->deploymentId)->toArray();
    }
}

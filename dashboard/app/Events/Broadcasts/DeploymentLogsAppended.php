<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge: new log lines were stored for a deployment. One per daemon
 * log batch (daemons ship at most every second); clients coalesce nudges and
 * fetch the lines after their cursor at most every 200 ms. Log text never
 * travels over the socket.
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
        return [new PrivateChannel("deployments.{$this->deploymentId}")];
    }

    public function broadcastAs(): string
    {
        return 'logs.appended';
    }
}

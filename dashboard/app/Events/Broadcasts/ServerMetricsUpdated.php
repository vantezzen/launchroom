<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\ServerMetricsData;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge: a server delivered new usage minutes (docs/design/direction.md
 * §8.2). Server cards and the server page refetch `/servers/-/metrics` or
 * `/servers/{server}/-/metrics`.
 */
final readonly class ServerMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $teamId,
        public string $serverId,
        public string $at,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [Channel::TeamServers->private($this->teamId)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::ServerMetrics->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new ServerMetricsData($this->serverId, $this->at)->toArray();
    }
}

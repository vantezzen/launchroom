<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge: a server delivered new usage minutes (docs/design/direction.md
 * §8.2). Carries ids and the newest minute only; server cards and the server
 * page refetch `/servers/-/metrics` or `/servers/{server}/-/metrics`.
 * Channel `servers.{team}` is authorized in routes/channels.php.
 */
final readonly class ServerMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public string $teamId,
        public string $serverId,
        /** Newest minute (ISO 8601) in the report. */
        public string $at,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("servers.{$this->teamId}")];
    }

    public function broadcastAs(): string
    {
        return 'server.metrics';
    }
}

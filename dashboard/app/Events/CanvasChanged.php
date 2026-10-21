<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something on an environment's canvas changed (a node's status, a staged
 * edit). Carries no data: viewers reload the canvas props, which re-checks
 * authorization. Channel: `environments.{public id}` (routes/channels.php).
 */
final class CanvasChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $environmentId,
        public readonly ?string $serviceId = null,
    ) {}

    public static function channelName(string $environmentId): string
    {
        return 'environments.'.$environmentId;
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->environmentId))];
    }

    public function broadcastAs(): string
    {
        return 'canvas.changed';
    }

    /**
     * @return array<string, string|null>
     */
    public function broadcastWith(): array
    {
        return ['serviceId' => $this->serviceId];
    }
}

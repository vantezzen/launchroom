<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Broadcasting\ServiceActivityTopic;
use App\Data\Broadcasts\ServiceActivityData;
use App\Models\ServiceInstance;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session, remote access, backup, restore or import of one service changed
 * state, mostly on a daemon's report. The tab showing it refetches instead of
 * polling or waiting a fixed delay.
 */
final readonly class ServiceActivityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $environmentId,
        public string $serviceId,
        public ServiceActivityTopic $topic,
    ) {}

    public static function about(?ServiceInstance $instance, ServiceActivityTopic $topic): void
    {
        if (! $instance instanceof ServiceInstance) {
            return;
        }

        $instance->loadMissing(['environment', 'service']);
        event(new self($instance->environment->public_id, $instance->service->public_id, $topic));
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
        return BroadcastEvent::ServiceActivity->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new ServiceActivityData($this->serviceId, $this->topic)->toArray();
    }
}

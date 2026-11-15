<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\SecurityChangedData;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A team's security findings were re-evaluated (after "Check now", a feed
 * sync or a new inventory). The Security page refetches.
 */
final readonly class SecurityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $teamId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [Channel::TeamSecurity->private($this->teamId)];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::SecurityChanged->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new SecurityChangedData($this->teamId)->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Events\Broadcasts;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\DeploymentUpdatedData;
use App\Enums\DeploymentStatus;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Realtime nudge about a deployment (docs/08 §4 "realtime by default").
 * Carries ids and the status only; clients refetch what they show through
 * authorized endpoints.
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
        /** The membership public id of whoever started it: also nudge their own channel. */
        public ?string $memberId = null,
        public ?string $projectId = null,
        public ?string $serviceName = null,
        public ?string $commitSha = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            Channel::Environment->private($this->environmentId),
            Channel::Deployment->private($this->deploymentId),
            ...($this->memberId === null ? [] : [Channel::Member->private($this->memberId)]),
        ];
    }

    public function broadcastAs(): string
    {
        return BroadcastEvent::DeploymentUpdated->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new DeploymentUpdatedData(
            $this->deploymentId,
            $this->environmentId,
            $this->serviceId,
            DeploymentStatus::from($this->status),
            $this->statusChanged,
            $this->projectId,
            $this->serviceName,
            $this->commitSha,
        )->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Data\Broadcasts\CanvasChangedData;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something on an environment's canvas changed (a node's status, a staged
 * edit). Carries no data: viewers reload the canvas props, which re-checks
 * authorization.
 *
 * Sent by the Action that changed what a canvas shows, after its transaction
 * commits, to every viewer including the one who made the change (docs/10
 * §2.1): the staged-changes pill and prefetched panels are client queries
 * that the requester's own response doesn't refresh.
 */
final class CanvasChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $environmentId,
        public readonly ?string $serviceId = null,
    ) {}

    /**
     * An environment by id (null: nothing on a canvas, e.g. a server-wide alert).
     */
    public static function forEnvironment(?int $environmentId): void
    {
        $environment = $environmentId === null ? null : Environment::query()->whereKey($environmentId)->value('public_id');

        if (is_string($environment)) {
            event(new self($environment));
        }
    }

    public static function forInstance(ServiceInstance $instance): void
    {
        $instance->loadMissing(['environment', 'service']);
        event(new self($instance->environment->public_id, $instance->service->public_id));
    }

    /**
     * Every environment the service runs in (a project-level edit such as a
     * rename shows on each of their canvases).
     */
    public static function forService(Service $service): void
    {
        foreach (Environment::query()->whereHas('serviceInstances', static fn (Builder $query) => $query->where('service_id', $service->id))->pluck('public_id') as $environment) {
            if (is_string($environment)) {
                event(new self($environment, $service->public_id));
            }
        }
    }

    /**
     * Every environment of the project (shared variables, connections).
     */
    public static function forProject(Project|int $project): void
    {
        foreach (Environment::query()->where('project_id', $project instanceof Project ? $project->id : $project)->pluck('public_id') as $environment) {
            if (is_string($environment)) {
                event(new self($environment));
            }
        }
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
        return BroadcastEvent::CanvasChanged->value;
    }

    /**
     * @return array<mixed>
     */
    public function broadcastWith(): array
    {
        return new CanvasChangedData($this->serviceId)->toArray();
    }
}

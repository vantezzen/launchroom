<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Server;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The desired state of these servers may have changed (intent, routes,
 * deployments). A listener queues one debounced re-render per server.
 *
 * Actions name what changed through the named constructors (a server's own
 * state, the team's routes, the mesh) instead of assembling id lists; an
 * empty set dispatches nothing.
 */
final readonly class NodeStateInvalidated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $serverIds
     */
    public function __construct(
        public array $serverIds,
    ) {}

    /**
     * @param  iterable<mixed>  $serverIds
     */
    public static function servers(iterable $serverIds): void
    {
        $ids = [];

        foreach ($serverIds as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids !== []) {
            event(new self(array_keys($ids)));
        }
    }

    /**
     * Routes of the team changed: its edges plus any other servers whose own
     * state changed with them.
     *
     * @param  iterable<mixed>  $also
     */
    public static function edges(int $teamId, iterable $also = []): void
    {
        self::servers([...$also, ...Server::query()->where('team_id', $teamId)->edges()->pluck('id')->all()]);
    }

    /**
     * Mesh membership or addresses changed: every mesh member renders its
     * peers again.
     *
     * @param  iterable<mixed>  $also
     */
    public static function mesh(iterable $also = []): void
    {
        self::servers([...$also, ...Server::query()->meshMembers()->pluck('id')->all()]);
    }
}

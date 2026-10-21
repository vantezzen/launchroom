<?php

declare(strict_types=1);

namespace App\Jobs\Mesh;

use App\Actions\Mesh\RemoveMeshNode;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A server was revoked: delete its mesh node (docs/09 R25). Retried while
 * Headscale is unreachable; MaintainMesh deletes leftovers later.
 */
#[Backoff([10, 30, 60, 120])]
#[Timeout(60)]
#[Tries(5)]
final class RemoveMeshNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $serverId,
    ) {}

    public function handle(RemoveMeshNode $removeMeshNode): void
    {
        $server = Server::query()->whereKey($this->serverId)->whereNotNull('revoked_at')->first();

        if ($server !== null) {
            $removeMeshNode->handle($server);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Ingress\ProvisionCertStorage;
use App\Actions\Mesh\AllocateMeshPorts;
use App\Actions\Traces\ProvisionTraceKeys;
use App\Events\NodeStateInvalidated;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings up to date what a render reads but never writes, at the start of
 * the queued render (App\Jobs\RenderServerNodeState), outside any caller's
 * transaction: the mesh ports of the server's team (AllocateMeshPorts takes
 * one global lock, which must never be held across a caller's transaction)
 * and the shared certificate storage (Valkey ACL I/O), and the server's
 * request trace key (docs/23 §4). Other servers that got a port re-render so
 * they publish it.
 */
final readonly class PrepareNodeStateRender
{
    public function __construct(
        private AllocateMeshPorts $allocate,
        private ProvisionCertStorage $certStorage,
        private ProvisionTraceKeys $traceKeys,
    ) {}

    public function handle(Server $server): void
    {
        // The server itself renders right after this, with its new ports.
        NodeStateInvalidated::servers(array_diff($this->allocate->handle([$server->team_id]), [$server->id]));

        try {
            $this->certStorage->handle(throttle: true);
        } catch (Throwable $e) {
            // The scheduled command retries; the edges keep their mirror meanwhile.
            Log::warning('Could not provision the shared certificate storage on Valkey.', ['error' => $e::class]);
        }

        try {
            $this->traceKeys->handle($server);
        } catch (Throwable $e) {
            // The scheduled command retries; the edge keeps its current key meanwhile.
            Log::warning('Could not provision the request trace key.', ['server' => $server->public_id, 'error' => $e::class]);
        }
    }
}

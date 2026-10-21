<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Daemons\StoreLogBatch;
use App\Data\Protocol\V1\LogBatch;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /daemon/v1/logs → 204.
 */
final class LogController extends Controller
{
    public function __invoke(Request $request, StoreLogBatch $storeLogBatch): Response|JsonResponse
    {
        $server = AuthenticateDaemon::server($request);

        // Pending servers aren't verified yet: they only enroll (review L1).
        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $storeLogBatch->handle($server, ProtocolPayload::decode($request, LogBatch::class));

        return new Response(status: 204);
    }
}

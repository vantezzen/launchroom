<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Servers\RecordReport;
use App\Data\Protocol\V1\Report;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /daemon/v1/reports → 204.
 */
final class ReportController extends Controller
{
    public function __invoke(Request $request, RecordReport $recordReport): Response|JsonResponse
    {
        $server = AuthenticateDaemon::server($request);

        // Pending servers aren't verified yet: they only enroll (review L1).
        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $recordReport->handle($server, ProtocolPayload::decode($request, Report::class));

        return new Response(status: 204);
    }
}

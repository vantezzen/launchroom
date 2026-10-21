<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Daemons\LeaseTasks;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\TaskLease;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /daemon/v1/tasks/lease (non-blocking, 0..n tasks).
 */
final class LeaseTasksController extends Controller
{
    public function __invoke(Request $request, LeaseTasks $leaseTasks): Response
    {
        $server = AuthenticateDaemon::server($request);

        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $tasks = $leaseTasks->handle($server, ProtocolPayload::decode($request, LeaseRequest::class));

        return ProtocolResponse::make(new TaskLease($tasks));
    }
}

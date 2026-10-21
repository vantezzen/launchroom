<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Daemons\HeartbeatTask;
use App\Data\Protocol\V1\TaskHeartbeat;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /daemon/v1/tasks/{task}/heartbeat. Only tasks held by the
 * authenticated server resolve (404 otherwise).
 */
final class TaskHeartbeatController extends Controller
{
    public function __invoke(Request $request, string $task, HeartbeatTask $heartbeatTask): Response
    {
        $server = AuthenticateDaemon::server($request);
        $daemonTask = $server->tasks()->where('public_id', $task)->firstOrFail();

        return ProtocolResponse::make($heartbeatTask->handle(
            $server,
            $daemonTask,
            ProtocolPayload::decode($request, TaskHeartbeat::class),
        ));
    }
}

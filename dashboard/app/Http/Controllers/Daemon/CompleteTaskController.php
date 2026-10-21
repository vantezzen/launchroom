<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Daemons\CompleteTask;
use App\Data\Protocol\V1\TaskCompletion;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /daemon/v1/tasks/{task}/complete with `Idempotency-Key: <taskId>:<attempt>`.
 */
final class CompleteTaskController extends Controller
{
    public function __invoke(Request $request, string $task, CompleteTask $completeTask): Response|JsonResponse
    {
        $server = AuthenticateDaemon::server($request);
        $daemonTask = $server->tasks()->where('public_id', $task)->firstOrFail();
        $completion = ProtocolPayload::decode($request, TaskCompletion::class);

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || ! hash_equals("{$daemonTask->public_id}:{$completion->attempt}", $key)) {
            return new JsonResponse(['error' => 'Idempotency-Key must be <taskId>:<attempt>'], 422);
        }

        $completeTask->handle($server, $daemonTask, $completion);

        return new Response(status: 204);
    }
}

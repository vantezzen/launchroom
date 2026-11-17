<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Sessions\AuthorizeServerSessionChannel;
use App\Broadcasting\Channel;
use App\Data\Protocol\V1\BroadcastAuth;
use App\Data\Protocol\V1\BroadcastAuthRequest;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use App\Services\Daemon\DaemonNudger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /daemon/v1/broadcasting/auth: authorizes the daemon's WebSocket
 * connection on its own private channel `private-daemon.{server}`, or on the
 * channel of an interactive session whose task it holds (docs/03 §11), and
 * nothing else (docs/03 §4). Browser sessions can never join daemon channels
 * (routes/channels.php denies them); this signed endpoint is the only way in.
 */
final class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request, DaemonNudger $nudger, AuthorizeServerSessionChannel $sessionChannel): Response
    {
        $server = AuthenticateDaemon::server($request);

        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $payload = ProtocolPayload::decode($request, BroadcastAuthRequest::class);

        if (! hash_equals(Channel::Daemon->wire($server->public_id), $payload->channel) && ! $sessionChannel->handle($server, $payload->channel)) {
            return new JsonResponse(['error' => 'a daemon may only join its own channel'], 403);
        }

        $auth = $nudger->authorize($payload->socketId, $payload->channel);

        if ($auth === null) {
            return new JsonResponse(['error' => 'realtime nudges are off'], 404);
        }

        return ProtocolResponse::make(new BroadcastAuth($auth))->setPrivate()->header('Cache-Control', 'no-store');
    }
}

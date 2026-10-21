<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Data\Protocol\V1\BroadcastAuth;
use App\Data\Protocol\V1\BroadcastAuthRequest;
use App\Enums\DaemonTaskStatus;
use App\Enums\Sessions\InteractiveSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use App\Models\Server;
use App\Models\Sessions\InteractiveSession;
use App\Services\Daemon\DaemonNudger;
use App\Support\PublicId;
use Illuminate\Contracts\Database\Query\Builder;
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
    public function __invoke(Request $request, DaemonNudger $nudger): Response
    {
        $server = AuthenticateDaemon::server($request);

        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $payload = ProtocolPayload::decode($request, BroadcastAuthRequest::class);

        if (! hash_equals('private-'.DaemonNudger::channel($server->public_id), $payload->channel) && ! $this->holdsSession($server, $payload->channel)) {
            return new JsonResponse(['error' => 'a daemon may only join its own channel'], 403);
        }

        $auth = $nudger->authorize($payload->socketId, $payload->channel);

        if ($auth === null) {
            return new JsonResponse(['error' => 'realtime nudges are off'], 404);
        }

        return ProtocolResponse::make(new BroadcastAuth($auth))->setPrivate()->header('Cache-Control', 'no-store');
    }

    /**
     * `private-session.<ses>` of an open interactive session whose task this
     * server holds right now (docs/03 §11.2).
     */
    private function holdsSession(Server $server, string $channel): bool
    {
        if (preg_match('/^private-session\.(ses_'.PublicId::ULID_PATTERN.')$/D', $channel, $match) !== 1) {
            return false;
        }

        return InteractiveSession::query()
            ->where('public_id', $match[1])
            ->where('server_id', $server->id)
            ->where('status', InteractiveSessionStatus::Open->value)
            ->whereHas('daemonTask', static fn (Builder $query) => $query
                ->where('server_id', $server->id)
                ->where('status', DaemonTaskStatus::Leased->value))
            ->exists();
    }
}

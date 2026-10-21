<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Server;
use App\Services\Daemon\ProtocolVersions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protocol compatibility (docs/03 §10). launchroomd advertises the protocol
 * versions it speaks in `X-Lr-Protocol-Versions` on every request; the
 * dashboard speaks `launchroom.daemon.protocol_versions` (current and N-1).
 * Without a common version the request is refused with 426, so an
 * incompatible daemon never acts on documents it can't understand. Runs
 * after AuthenticateDaemon; records the negotiated version on the server.
 */
final class NegotiateProtocolVersion
{
    public const string HEADER = 'X-Lr-Protocol-Versions';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $version = ProtocolVersions::negotiate(ProtocolVersions::parse((string) $request->headers->get(self::HEADER, '')));

        if ($version === null) {
            return new JsonResponse([
                'error' => 'unsupported_protocol',
                'message' => 'launchroomd and the dashboard share no protocol version. Update launchroomd on this server.',
                'supported' => ProtocolVersions::supported(),
            ], 426);
        }

        $server = $this->server($request);

        if ($server instanceof Server && $server->protocol_version !== $version) {
            Server::query()->whereKey($server->id)->update(['protocol_version' => $version]);
            $server->protocol_version = $version;
        }

        return $next($request);
    }

    private function server(Request $request): ?Server
    {
        try {
            return AuthenticateDaemon::server($request);
        } catch (LogicException) {
            return null; // enroll: no server yet.
        }
    }
}

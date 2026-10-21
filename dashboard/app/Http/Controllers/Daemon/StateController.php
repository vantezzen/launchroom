<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Servers\RenderNodeState;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /daemon/v1/state?have=N → 304 when the daemon is current, else the
 * latest signed NodeState of the authenticated server (docs/03 §4). A
 * server replaced by "Replace server" gets its last state, the empty
 * decommission NodeState (docs/14 §5.3).
 */
final class StateController extends Controller
{
    public function __invoke(Request $request, RenderNodeState $renderNodeState): Response
    {
        $server = AuthenticateDaemon::server($request);

        // A replaced server only gets its last (empty, decommission) state; nothing is rendered for it anymore.
        $decommissioned = $server->status === ServerStatus::Revoked && $server->replaced_at !== null;

        if (! $server->status->isApproved() && ! $decommissioned) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $have = $request->query('have', '0');

        if (! is_string($have) || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $have) !== 1) {
            return new JsonResponse(['error' => 'have must be a non-negative integer'], 422);
        }

        $latest = $server->latestNodeState()->first() ?? ($decommissioned ? null : $renderNodeState->handle($server)->version);

        if ($latest === null) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        if ($latest->version <= (int) $have) {
            return new Response(status: 304);
        }

        return ProtocolResponse::raw($latest->document);
    }
}

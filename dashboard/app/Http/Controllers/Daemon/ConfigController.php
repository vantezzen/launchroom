<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Data\Protocol\V1\DaemonConfig;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use App\Services\Daemon\DaemonNudger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /daemon/v1/config: daemon settings that may change after enrollment
 * (realtime nudge endpoint, docs/03 §4). Nothing in it is secret: the Pusher
 * key is public and channel authorizations come from broadcasting/auth.
 * Servers that are not approved get no nudges (they can't subscribe either).
 */
final class ConfigController extends Controller
{
    public function __invoke(Request $request, DaemonNudger $nudger): Response
    {
        $server = AuthenticateDaemon::server($request);

        return ProtocolResponse::make(new DaemonConfig(nudge: $server->status->isApproved() ? $nudger->config() : null));
    }
}

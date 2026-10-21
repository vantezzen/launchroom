<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Registry\IssueDaemonRegistryToken;
use App\Data\Registry\RegistryTokenRequest;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /daemon/v1/registry-token: a short-lived, scoped registry token for the
 * authenticated server (docs/05 §6). 403 when any scope is not allowed.
 */
final class RegistryTokenController extends Controller
{
    public function __invoke(Request $request, IssueDaemonRegistryToken $issueDaemonRegistryToken): Response
    {
        $server = AuthenticateDaemon::server($request);

        if (! $server->status->isApproved()) {
            return new JsonResponse(['error' => 'server is not approved'], 403);
        }

        $token = $issueDaemonRegistryToken->handle($server, ProtocolPayload::decode($request, RegistryTokenRequest::class));

        return ProtocolResponse::make($token)->setPrivate()->header('Cache-Control', 'no-store');
    }
}

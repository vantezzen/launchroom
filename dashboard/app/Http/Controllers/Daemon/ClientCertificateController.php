<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Daemons\IssueClientCertificate;
use App\Data\Daemon\ClientCertificateRequest;
use App\Exceptions\Daemon\ClientCertificateRefused;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Http\Middleware\AuthenticateDaemon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /daemon/v1/client-certificate (mTLS, D50, docs/03 §4.3): a client
 * certificate for the key launchroomd generated. Pending servers may ask
 * too, so a server that waits for approval can still reach the dashboard
 * once certificates are required.
 */
final class ClientCertificateController extends Controller
{
    public function __invoke(Request $request, IssueClientCertificate $issue): Response
    {
        $server = AuthenticateDaemon::server($request);
        $payload = ProtocolPayload::decode($request, ClientCertificateRequest::class);

        try {
            $certificate = $issue->handle($server, $payload, AuthenticateDaemon::clientCertificate($request), $request->ip());
        } catch (ClientCertificateRefused $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status);
        }

        return ProtocolResponse::make($certificate)->setPrivate()->header('Cache-Control', 'no-store');
    }
}

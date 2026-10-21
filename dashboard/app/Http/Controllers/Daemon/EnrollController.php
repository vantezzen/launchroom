<?php

declare(strict_types=1);

namespace App\Http\Controllers\Daemon;

use App\Actions\Servers\EnrollServer;
use App\Data\Protocol\V1\EnrollRequest;
use App\Data\Protocol\V1\EnrollResponse;
use App\Data\Protocol\V1\PollIntervals;
use App\Data\Protocol\V1\RegistryInfo;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Daemon\ProtocolPayload;
use App\Http\Daemon\ProtocolResponse;
use App\Services\Crypto\ControlPlaneKeys;
use App\Services\Daemon\DaemonNudger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /daemon/v1/enroll. Authenticated by the request signature with keyid
 * "enroll" (proof of key possession); authorized by the join token.
 */
final class EnrollController extends Controller
{
    public function __invoke(Request $request, EnrollServer $enrollServer, ControlPlaneKeys $keys, DaemonNudger $nudger): Response
    {
        $enrollRequest = ProtocolPayload::decode($request, EnrollRequest::class);

        $server = $enrollServer->handle($enrollRequest, $request->ip());

        /** @var array{state: string, tasks: string} $intervals */
        $intervals = config()->array('launchroom.daemon.poll_intervals');

        return ProtocolResponse::make(new EnrollResponse(
            serverId: $server->public_id,
            status: $server->status === ServerStatus::PendingApproval ? 'pending_approval' : 'active',
            controlPlaneKeys: $keys->trusted(),
            registry: new RegistryInfo(config()->string('launchroom.daemon.registry_address')),
            pollIntervals: new PollIntervals($intervals['state'], $intervals['tasks']),
            nudge: $nudger->config(),
        ), 201);
    }
}

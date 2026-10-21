<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\ClaimCliLoginToken;
use App\Actions\Api\StartCliLogin;
use App\Data\Api\ClaimCliTokenInput;
use App\Data\Api\CliSessionData;
use App\Data\Api\CliTokenData;
use App\Data\Api\StartCliSessionInput;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The CLI's device-code sign-in (docs/16 §4). Public by necessity: the
 * device code is the credential for polling, and nothing is granted until a
 * signed-in member approves the matching user code in the dashboard.
 */
final class CliSessionController extends Controller
{
    #[ApiOperation(id: 'startCliSession', summary: 'Start a CLI sign-in', tag: 'CLI sign-in', response: CliSessionData::class, request: StartCliSessionInput::class, status: 201, description: 'Open `verificationUrlComplete` in a browser, then poll `POST /cli/sessions/token` every `interval` seconds.')]
    public function store(Request $request, StartCliSessionInput $input, StartCliLogin $start): JsonResponse
    {
        return ApiResponse::item($start->handle($input, $request->ip(), $request->userAgent()), 201);
    }

    #[ApiOperation(id: 'claimCliToken', summary: 'Poll for the token of a CLI sign-in', tag: 'CLI sign-in', response: CliTokenData::class, request: ClaimCliTokenInput::class, description: '`428 authorization_pending` until approved, `403 access_denied` when denied, `410 expired_token` when expired or already picked up. The token is returned once.')]
    public function token(ClaimCliTokenInput $input, ClaimCliLoginToken $claim): JsonResponse
    {
        return ApiResponse::item($claim->handle($input->deviceCode))->header('Cache-Control', 'no-store, private');
    }
}

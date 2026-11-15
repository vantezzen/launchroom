<?php

declare(strict_types=1);

namespace App\Http\Controllers\Moves;

use App\Actions\Moves\AbortHandover;
use App\Actions\Moves\CheckInMoveReceiver;
use App\Actions\Moves\ClaimControlPlaneMove;
use App\Actions\Moves\CompleteHandover;
use App\Data\Audit\AuditActor;
use App\Data\Moves\AbortHandoverData;
use App\Data\Moves\ClaimMoveData;
use App\Data\Moves\CompleteHandoverData;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Http\Controllers\Controller;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Services\Crypto\Signer;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\MoveToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What the new machine of a control-plane move talks to (docs/14 §13.4).
 * Not a user or a server yet: the move token's secret (Bearer, compared by
 * its hash) is the credential, and only for its own move. Answers are
 * signed (SignResponse) and carry `kind: launchroom-move/1`, which no
 * daemon document has, so a signed answer here can't pass for a desired
 * state. Sealed values (the join token, the hand-over key and the key
 * bundle) can only be opened with the new machine's private key.
 */
final class MoveReceiverController extends Controller
{
    public function claim(Request $request, ClaimMoveData $data, ClaimControlPlaneMove $claim): JsonResponse
    {
        $move = ControlPlaneMove::query()->where('public_id', $data->move)->first();
        abort_unless($move instanceof ControlPlaneMove, 403, 'This move token is not valid.');

        return $this->answer($claim->handle($move, $data, $request->ip()));
    }

    public function show(Request $request, string $controlPlaneMove, CheckInMoveReceiver $checkIn): JsonResponse
    {
        return $this->answer($checkIn->handle($this->authenticate($request, $controlPlaneMove)));
    }

    public function chunk(Request $request, string $controlPlaneMove, int $index, HandoverStore $store): Response
    {
        $found = $this->authenticate($request, $controlPlaneMove);
        abort_unless($found->status === ControlPlaneMoveStatus::HandoverReady, 409, 'No hand-over is ready.');
        $chunks = $found->handover->database->chunks ?? 0;
        abort_unless($index < $chunks, 422, 'chunk index out of range');
        $body = $store->read($found->public_id, $index);
        abort_if($body === null, 410);

        return new Response($body, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function keys(Request $request, string $controlPlaneMove, HandoverStore $store): Response
    {
        $found = $this->authenticate($request, $controlPlaneMove);
        abort_unless($found->status === ControlPlaneMoveStatus::HandoverReady, 409, 'No hand-over is ready.');
        $body = $store->readKeys($found->public_id);
        abort_if($body === null, 410);

        return new Response($body, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function complete(Request $request, string $controlPlaneMove, CompleteHandover $complete): JsonResponse
    {
        $found = $this->authenticate($request, $controlPlaneMove);
        $data = CompleteHandoverData::validateAndCreate($request->all());

        return $this->answer($complete->handle($found, $data->url));
    }

    public function abort(Request $request, string $controlPlaneMove, AbortHandover $abort): JsonResponse
    {
        $found = $this->authenticate($request, $controlPlaneMove);
        $data = AbortHandoverData::validateAndCreate($request->all());

        return $this->answer($abort->handle($found, $data->reason ?? 'the new server reported a failure', AuditActor::system()));
    }

    private function authenticate(Request $request, string $publicId): ControlPlaneMove
    {
        $secret = (string) $request->bearerToken();
        $move = ControlPlaneMove::query()->where('public_id', $publicId)->first();

        abort_unless(
            $move instanceof ControlPlaneMove
                && $secret !== ''
                && hash_equals($move->token_hash, MoveToken::hashSecret($secret))
                && $move->receiver_public_key !== null
                && $move->status !== ControlPlaneMoveStatus::Cancelled,
            403,
            'This move token is not valid.',
        );

        return $move;
    }

    private function answer(ControlPlaneMove $move): JsonResponse
    {
        $move->loadMissing(['source', 'target']);
        $ready = $move->status === ControlPlaneMoveStatus::HandoverReady;

        return new JsonResponse(array_filter([
            'kind' => 'launchroom-move/1',
            'move' => $move->public_id,
            'status' => $move->status->value,
            'code' => $move->claim_code,
            'message' => $move->error,
            'cpKey' => resolve(Signer::class)->publicKey(),
            'server' => $move->source instanceof Server ? $move->source->name : null,
            'joined' => $move->target instanceof Server ? $move->target->name : null,
            // Only while it is needed; sealed to the new machine.
            'joinToken' => $move->status === ControlPlaneMoveStatus::Joining ? $move->sealed_join_token : null,
            'handover' => $ready ? $move->handover : null,
            'newUrl' => $move->new_url,
        ], static fn (mixed $value): bool => $value !== null), 200, [], JSON_UNESCAPED_SLASHES);
    }
}

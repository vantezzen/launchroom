<?php

declare(strict_types=1);

namespace App\Http\Controllers\Moves;

use App\Actions\Moves\AbortHandover;
use App\Actions\Moves\AdvanceControlPlaneMoves;
use App\Actions\Moves\ClaimControlPlaneMove;
use App\Actions\Moves\CompleteHandover;
use App\Data\Audit\AuditActor;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Http\Controllers\Controller;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Services\Crypto\Signer;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\MoveToken;
use App\Support\PublicId;
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
    public function claim(Request $request, ClaimControlPlaneMove $claim): JsonResponse
    {
        $request->validate([
            'move' => ['required', 'string', 'regex:'.PublicId::regex('cpm')],
            'secret' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'publicKey' => ['required', 'string', 'max:64'],
            'hostname' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'arch' => ['required', 'string', 'max:16', 'regex:/^[a-z0-9_]+$/'],
            'version' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9.+-]+$/'],
        ]);

        $move = ControlPlaneMove::query()->where('public_id', $request->string('move')->toString())->first();
        abort_unless($move instanceof ControlPlaneMove, 403, 'This move token is not valid.');

        $move = $claim->handle(
            $move,
            $request->string('secret')->toString(),
            $request->string('publicKey')->toString(),
            [
                'hostname' => $request->string('hostname')->toString(),
                'arch' => $request->string('arch')->toString(),
                'version' => $request->filled('version') ? $request->string('version')->toString() : null,
            ],
            $request->ip(),
        );

        return $this->answer($move);
    }

    public function show(Request $request, string $move, AdvanceControlPlaneMoves $advance): JsonResponse
    {
        $found = $this->authenticate($request, $move);
        $found->forceFill(['receiver_seen_at' => now()])->save();

        // Joining → joined as soon as its launchroomd enrolled.
        if ($found->status === ControlPlaneMoveStatus::Joining) {
            $advance->handle();
            $found->refresh();
        }

        return $this->answer($found);
    }

    public function chunk(Request $request, string $move, int $index, HandoverStore $store): Response
    {
        $found = $this->authenticate($request, $move);
        abort_unless($found->status === ControlPlaneMoveStatus::HandoverReady, 409, 'No hand-over is ready.');
        $chunks = $found->handover['database']['chunks'] ?? 0;
        abort_unless($index < $chunks, 422, 'chunk index out of range');
        $body = $store->read($found->public_id, $index);
        abort_if($body === null, 410);

        return new Response($body, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function keys(Request $request, string $move, HandoverStore $store): Response
    {
        $found = $this->authenticate($request, $move);
        abort_unless($found->status === ControlPlaneMoveStatus::HandoverReady, 409, 'No hand-over is ready.');
        $body = $store->readKeys($found->public_id);
        abort_if($body === null, 410);

        return new Response($body, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function complete(Request $request, string $move, CompleteHandover $complete): JsonResponse
    {
        $found = $this->authenticate($request, $move);
        $request->validate(['url' => ['required', 'string', 'max:255']]);

        return $this->answer($complete->handle($found, $request->string('url')->toString()));
    }

    public function abort(Request $request, string $move, AbortHandover $abort): JsonResponse
    {
        $found = $this->authenticate($request, $move);
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->answer($abort->handle($found, $request->filled('reason') ? $request->string('reason')->toString() : 'the new server reported a failure', AuditActor::system()));
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

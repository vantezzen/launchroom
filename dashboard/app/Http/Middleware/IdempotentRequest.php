<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` on API creates (docs/16 §2.1). The first request with a
 * key claims it (unique per token) and stores its status and body when it
 * finishes; a replay gets the stored response with `Idempotent-Replayed`,
 * the same key for a different request is refused, and a replay while the
 * first still runs is a conflict. 5xx answers are not kept (retry is safe).
 */
final class IdempotentRequest
{
    public const string HEADER = 'Idempotency-Key';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);
        $user = $request->user();
        $token = $user instanceof User ? $user->currentApiToken() : null;

        if ($key === null || ! $token instanceof ApiToken || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_.:-]{1,255}$/D', $key) !== 1) {
            throw ValidationException::withMessages([self::HEADER => ['Use 1 to 255 letters, digits or _ . : - for the idempotency key.']]);
        }

        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());

        ApiIdempotencyKey::query()
            ->where('personal_access_token_id', $token->id)
            ->where('key', $key)
            ->where('created_at', '<', now()->subHours(config()->integer('api.idempotency_hours')))
            ->delete();

        $claimed = ApiIdempotencyKey::query()->insertOrIgnore([
            'personal_access_token_id' => $token->id,
            'key' => $key,
            'request_hash' => $hash,
            'created_at' => now(),
        ]) === 1;

        if (! $claimed) {
            return $this->replay($token->id, $key, $hash);
        }

        $response = $next($request);
        $stored = ApiIdempotencyKey::query()->where('personal_access_token_id', $token->id)->where('key', $key);

        if ($response->getStatusCode() >= 500) {
            $stored->delete();

            return $response;
        }

        $row = $stored->first();

        if ($row instanceof ApiIdempotencyKey) {
            $row->status = $response->getStatusCode();
            $row->response = (string) $response->getContent();
            $row->save();
        }

        return $response;
    }

    private function replay(int $tokenId, string $key, string $hash): Response
    {
        $existing = ApiIdempotencyKey::query()->where('personal_access_token_id', $tokenId)->where('key', $key)->first();

        if ($existing === null) {
            throw new ApiError('idempotency_key_in_progress', 'A request with this idempotency key is still running. Try again in a moment.', 409);
        }

        if (! hash_equals($existing->request_hash, $hash)) {
            throw new ApiError('idempotency_key_reused', 'This idempotency key was already used for a different request.', 422);
        }

        if ($existing->status === null) {
            throw new ApiError('idempotency_key_in_progress', 'A request with this idempotency key is still running. Try again in a moment.', 409);
        }

        return new Response((string) $existing->response, $existing->status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}

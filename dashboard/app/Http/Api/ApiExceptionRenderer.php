<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Exceptions\ApiError;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\GitHeadUnavailable;
use App\Exceptions\MissingApiAbility;
use App\Exceptions\UnresolvedReferences;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error format for every 4xx/5xx under /api/v1 (docs/16 §2.1):
 * `{"error": {"code", "message", "fields"?, "ability"?}}`. Unexpected
 * exceptions are reported by the handler and answered as `server_error`
 * without internals.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        // Expected refusals, not errors worth a log line.
        $exceptions->dontReport([ApiError::class, MissingApiAbility::class]);

        $exceptions->render(static fn (Throwable $e, Request $request): ?JsonResponse => $request->is('api/v1', 'api/v1/*') ? self::render($e) : null);
    }

    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiError => self::error($e->status, $e->errorCode, $e->getMessage()),
            $e instanceof MissingApiAbility => self::error(403, 'missing_ability', $e->getMessage(), ['ability' => $e->ability->value]),
            $e instanceof AuthenticationException => self::error(401, 'unauthenticated', 'Sign in again: this token is missing, expired or revoked.'),
            $e instanceof ValidationException => self::error(422, 'validation_failed', 'Some fields are invalid.', ['fields' => $e->errors()]),
            $e instanceof DomainRuleViolation => self::error($e->status, 'rule_violation', $e->getMessage(), $e->field === 'general' ? [] : ['fields' => [$e->field => [$e->getMessage()]]]),
            $e instanceof UnresolvedReferences => self::render($e->toDomainRuleViolation()),
            $e instanceof GitHeadUnavailable => self::error(422, 'rule_violation', $e->getMessage()),
            $e instanceof HttpExceptionInterface => self::http($e),
            default => self::error(500, 'server_error', 'Something went wrong on the server. It has been logged.'),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function error(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status, $headers);
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        /** @var array<string, string> $headers */
        $headers = $e->getHeaders();

        [$code, $message] = match ($status) {
            403 => ['forbidden', "You don't have permission to do that."],
            404 => ['not_found', 'Not found. It may not exist, or it belongs to another team.'],
            405 => ['method_not_allowed', 'This endpoint does not support that method.'],
            409 => ['conflict', $e->getMessage() !== '' ? $e->getMessage() : 'Conflict.'],
            429 => ['rate_limited', 'Too many requests. Wait a moment and try again.'],
            default => [$status >= 500 ? 'server_error' : 'bad_request', $status >= 500 ? 'Something went wrong on the server.' : 'The request could not be handled.'],
        };

        return self::error($status, $code, $message, [], $headers);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiTokens;

use App\Actions\Api\ApproveCliLogin;
use App\Actions\Api\DenyCliLogin;
use App\Data\Api\ApproveCliLoginInput;
use App\Data\Api\CliLoginRequestData;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\CliLoginRequest;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Support\Api\CliUserCode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Sign in to the launchroom CLI?" (docs/16 §4): the person compares the
 * code with their terminal, picks the team, abilities and expiry, and
 * approves or denies. The user code is the only handle; it resolves a
 * pending request (or one this user answered), nothing else.
 */
final class CliLoginController extends Controller
{
    public function show(Request $request, #[CurrentUser] User $user, ApiTokenOptions $options): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        $code = CliUserCode::normalize($request->string('code')->toString());
        $login = $code === null ? null : $this->byCode($code);

        if ($login instanceof CliLoginRequest) {
            Gate::authorize('view', $login);
        }

        return Inertia::render('cli/login', [
            'code' => $code,
            'login' => $login instanceof CliLoginRequest ? new CliLoginRequestData(
                userCode: $login->user_code,
                status: $login->status,
                expired: $login->isExpired(),
                clientName: $login->client_name,
                hostname: $login->client_hostname,
                version: $login->client_version,
                ip: $login->ip,
                createdAt: $login->created_at->toIso8601String(),
                expiresAt: $login->expires_at->toIso8601String(),
            ) : null,
            'teamOptions' => $options->teams($user),
            'lifetimes' => ApiTokenOptions::lifetimes(),
            'defaultLifetime' => config()->integer('api.cli_token_days'),
        ]);
    }

    public function approve(Request $request, string $code, ApproveCliLoginInput $input, #[CurrentUser] User $user, ApproveCliLogin $approve): RedirectResponse
    {
        $login = $this->pending($code);
        Gate::authorize('answer', $login);

        $approve->handle($login, $user, $input, $this->auditActor($request, $user));

        return to_route('cli-login.show', ['code' => $login->user_code]);
    }

    public function deny(Request $request, string $code, #[CurrentUser] User $user, DenyCliLogin $deny): RedirectResponse
    {
        $login = $this->pending($code);
        Gate::authorize('answer', $login);

        $deny->handle($login, $user, $this->auditActor($request, $user));

        return to_route('cli-login.show', ['code' => $login->user_code]);
    }

    private function byCode(string $code): ?CliLoginRequest
    {
        return CliLoginRequest::query()->where('user_code', $code)->first();
    }

    private function pending(string $code): CliLoginRequest
    {
        $normalized = CliUserCode::normalize($code);
        $login = $normalized === null ? null : $this->byCode($normalized);

        abort_unless($login instanceof CliLoginRequest, 404);

        return $login;
    }
}

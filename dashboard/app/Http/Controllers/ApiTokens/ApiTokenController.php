<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiTokens;

use App\Actions\Api\CreateApiToken;
use App\Actions\Api\RevokeApiToken;
use App\Data\Api\ApiTokenData;
use App\Data\Api\CreateApiTokenInput;
use App\Data\Api\CreatedApiTokenData;
use App\Enums\ApiTokenClient;
use App\Exceptions\DomainRuleViolation;
use App\Http\Auth\PasswordConfirmationThrottle;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API tokens (docs/16 §1.3): your tokens, create (password
 * confirmed inline, the plaintext in this one JSON response only) and
 * revoke.
 */
final class ApiTokenController extends Controller
{
    public function index(#[CurrentUser] User $user, ApiTokenOptions $options): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        return Inertia::render('settings/api-tokens', [
            'tokens' => $options->tokens($user),
            'teamOptions' => $options->teams($user),
            'lifetimes' => ApiTokenOptions::lifetimes(),
            'apiUrl' => url('/api/v1'),
            'cliInstall' => 'curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh',
        ]);
    }

    public function store(Request $request, CreateApiTokenInput $input, #[CurrentUser] User $user, CreateApiToken $create): JsonResponse
    {
        $team = $user->currentTeam ?? throw new DomainRuleViolation('Pick a team first.');
        Gate::authorize('create', [ApiToken::class, $team]);

        // Creating a token is a sensitive action (docs/09 R10), confirmed
        // inline like a secret reveal (a fetch can't follow the confirm page).
        if (! $this->passwordRecentlyConfirmed($request)) {
            if (! $request->filled('password')) {
                return response()->json(['message' => 'Confirm your password to create a token.', 'requiresPassword' => true], 423);
            }

            PasswordConfirmationThrottle::confirm($request, $user, $request->string('password')->toString());
            $request->session()->put('auth.password_confirmed_at', time());
        }

        $new = $create->handle($user, $team, $input->name, $input->abilityList(), $input->expiresInDays, ApiTokenClient::Dashboard, $this->auditActor($request, $user));

        return response()
            ->json(new CreatedApiTokenData(ApiTokenData::fromToken($new->token), $new->plainText)->toArray(), 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, ApiToken $apiToken, #[CurrentUser] User $user, RevokeApiToken $revoke): RedirectResponse
    {
        Gate::authorize('delete', $apiToken);

        $revoke->handle($apiToken, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$apiToken->name} revoked. Requests with it stop working now."]);

        return back();
    }

    private function passwordRecentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);

        return is_numeric($confirmedAt) && (time() - (int) $confirmedAt) < config()->integer('auth.password_timeout', 10800);
    }
}

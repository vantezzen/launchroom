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
 * Settings → API tokens (docs/16 §1.3): your tokens, create (the plaintext
 * in this one JSON response only) and revoke.
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
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\ApiTokens;

use App\Actions\Api\CreateRobotToken;
use App\Actions\Api\RevokeRobotToken;
use App\Actions\Api\RotateRobotToken;
use App\Data\Api\CreatedRobotTokenData;
use App\Data\Api\CreateRobotTokenInput;
use App\Data\Api\RobotTokenData;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use App\Queries\Api\ApiTokenOptions;
use App\Queries\Api\RobotTokens;
use App\Support\Api\NewApiToken;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Robot tokens (docs/16 §1.4): the current team's robots; owners
 * and admins create, rotate (the plaintext in that JSON response only) and
 * revoke them.
 */
final class RobotTokenController extends Controller
{
    public function index(#[CurrentUser] User $user, RobotTokens $robots, ApiTokenOptions $options): Response
    {
        $team = $this->team($user);
        Gate::authorize('viewRobotTokens', $team);
        $canManage = Gate::allows('manageRobotTokens', $team);
        $role = $user->roleIn($team);

        return Inertia::render('settings/robot-tokens', [
            'tokens' => $robots->forTeam($team),
            'teamName' => $team->name,
            'canManage' => $canManage,
            'abilities' => $canManage && $role instanceof TeamRole ? ApiTokenOptions::abilities($role) : [],
            'scopes' => $canManage ? $robots->scopes($team) : [],
            'lifetimes' => ApiTokenOptions::lifetimes(),
            'defaultLifetime' => ApiTokenOptions::defaultLifetime(),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    public function store(Request $request, CreateRobotTokenInput $input, #[CurrentUser] User $user, CreateRobotToken $create): JsonResponse
    {
        $team = $this->team($user);
        Gate::authorize('manageRobotTokens', $team);

        return $this->created($create->handle($user, $team, $input, $this->auditActor($request, $user)));
    }

    public function rotate(Request $request, ApiToken $robotToken, #[CurrentUser] User $user, RotateRobotToken $rotate): JsonResponse
    {
        Gate::authorize('manageRobotTokens', $robotToken->team);

        return $this->created($rotate->handle($robotToken, $this->auditActor($request, $user)));
    }

    public function destroy(Request $request, ApiToken $robotToken, #[CurrentUser] User $user, RevokeRobotToken $revoke): RedirectResponse
    {
        Gate::authorize('manageRobotTokens', $robotToken->team);

        $revoke->handle($robotToken, $this->auditActor($request, $user));
        Toast::success("{$robotToken->name} revoked. Requests that use it are refused from now on.");

        return back();
    }

    private function team(User $user): Team
    {
        return $user->currentTeam ?? throw new DomainRuleViolation('Pick a team first.');
    }

    private function created(NewApiToken $new): JsonResponse
    {
        $token = $new->token->load(['project', 'service', 'user.robotCreator:id,name']);

        return response()
            ->json(new CreatedRobotTokenData(RobotTokenData::fromToken($token, $token->user), $new->plainText)->toArray(), 201)
            ->header('Cache-Control', 'no-store, private');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiTeamData;
use App\Data\Api\ApiTokenData;
use App\Data\Api\ApiUserData;
use App\Data\Api\MeData;
use App\Http\Api\ApiOperation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The token itself, its owner and its team. Reads nothing but the token.
 */
final class MeController extends Controller
{
    use ScopesApiRoutes;

    #[ApiOperation(id: 'getMe', summary: 'The token, its user and team', tag: 'Account', response: MeData::class)]
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $token = $this->token($user);
        $team = $this->team($user);

        return ApiResponse::item(new MeData(
            token: ApiTokenData::fromToken($token),
            user: new ApiUserData($user->name, $user->email),
            team: new ApiTeamData($team->public_id, $team->name, $user->roleIn($team)),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * `GET /api/v1/me`: the token, its owner and its team, and its scope when
 * it is limited to one project or service (null: every project).
 */
final class MeData extends Data
{
    public function __construct(
        public ApiTokenData $token,
        public ApiUserData $user,
        public ApiTeamData $team,
        public ?ApiTokenScopeData $scope,
    ) {}
}

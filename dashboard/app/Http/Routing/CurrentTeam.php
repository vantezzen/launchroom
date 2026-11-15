<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\Team;
use App\Models\User;
use Attribute;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Injects the signed-in user's current team (`users.current_team_id`), the
 * team list pages and team-wide settings act on (docs/10 §2.3a). 404s when
 * the user has none.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CurrentTeam implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): Team
    {
        $user = $container->make(AuthFactory::class)->guard()->user();
        abort_unless($user instanceof User, 401);

        return self::of($user);
    }

    public static function of(User $user): Team
    {
        return $user->currentTeam()->firstOrFail();
    }
}

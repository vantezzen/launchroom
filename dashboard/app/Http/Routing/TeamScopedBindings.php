<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Variable;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Route-model bindings for team-owned resources (docs/09 R7).
 *
 * A resource only resolves if it belongs to one of the signed-in user's
 * teams; anything else is a 404, indistinguishable from a missing id. The
 * team is then taken from the resource (never from the session) and policies
 * check the role in that team.
 */
final class TeamScopedBindings
{
    /** @var array<string, class-string<Server|ServerEnrollment|Membership|TeamInvitation|Project|Environment|Service|ServiceInstance|Variable|Connection|Domain|Deployment>> */
    private const array TEAM_OWNED = [
        'server' => Server::class,
        'enrollment' => ServerEnrollment::class,
        'membership' => Membership::class,
        'invitation' => TeamInvitation::class,
        'project' => Project::class,
        'environment' => Environment::class,
        'service' => Service::class,
        'serviceInstance' => ServiceInstance::class,
        'variable' => Variable::class,
        'connection' => Connection::class,
        'domain' => Domain::class,
        'deployment' => Deployment::class,
    ];

    public static function register(): void
    {
        foreach (self::TEAM_OWNED as $parameter => $class) {
            Route::bind($parameter, static fn (string $value): Model => self::resolveTeamOwned($class, $value));
        }

        Route::bind('team', static function (string $value): Team {
            $user = self::user();

            if (! PublicId::isValid($value, Team::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(Team::class);
            }

            return Team::query()
                ->where('public_id', $value)
                ->whereIn('id', $user->teamIdsQuery())
                ->firstOrFail();
        });
    }

    /**
     * @param  class-string<Server|ServerEnrollment|Membership|TeamInvitation|Project|Environment|Service|ServiceInstance|Variable|Connection|Domain|Deployment>  $class
     */
    private static function resolveTeamOwned(string $class, string $value): Model
    {
        $user = self::user();

        if (! PublicId::isValid($value, $class::publicIdPrefix())) {
            throw (new ModelNotFoundException)->setModel($class);
        }

        return $class::query()
            ->where('public_id', $value)
            ->whereIn('team_id', $user->teamIdsQuery())
            ->firstOrFail();
    }

    private static function user(): User
    {
        $user = Auth::user();

        // Bindings on guest routes are a programming error: fail as not found.
        if (! $user instanceof User) {
            throw new ModelNotFoundException;
        }

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Routing;

use App\Models\AuditSink;
use App\Models\Connection;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Membership;
use App\Models\Moves\ServerMove;
use App\Models\Moves\ServerMoveItem;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Variable;
use App\Models\Volume;
use App\Support\PublicId;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The only route-model binder for resources a user reaches through their
 * teams (docs/09 R7, docs/10 §2.3a).
 *
 * A resource only resolves if its public id is well-formed (checked before
 * any query) and it belongs to one of the signed-in user's teams; anything
 * else is a 404, indistinguishable from a missing id. The team is then taken
 * from the resource (never from the session) and policies check the role in
 * that team. Every registered parameter also gets its route pattern from the
 * model's public-id prefix, so route files never restate prefixes.
 *
 * Domain providers register their own parameters here ({@see teamOwned()},
 * {@see teamOwnedOrInstance()}, {@see define()}); `routes/channels.php`
 * resolves raw ids through {@see find()}.
 */
final class TeamScopedBindings
{
    /** @var array<string, class-string<Model>> */
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
        'revision' => EnvironmentRevision::class,
        'volume' => Volume::class,
        'move' => ServerMove::class,
    ];

    /** @var array<string, array{class-string<Model>, Closure(Builder<Model>, User): void}> */
    private static array $parameters = [];

    public static function register(): void
    {
        self::teamOwned(self::TEAM_OWNED);

        self::define('team', Team::class, static function (Builder $query, User $user): void {
            $query->whereIn('id', $user->teamIdsQuery());
        });

        // A move's services; instance owners also reach the workload half of
        // Settings → Move launchroom, whatever team the control plane is in.
        self::define('item', ServerMoveItem::class, static function (Builder $query, User $user): void {
            $query->where(static function (Builder $query) use ($user): void {
                $query->whereIn('team_id', $user->teamIdsQuery());

                if ($user->is_instance_owner) {
                    $query->orWhereIn('server_move_id', ServerMove::query()->select('id')->where('control_plane', true));
                }
            });
        });

        // Settings → Security → Server certificates acts on any team's server (docs/03 §4.3).
        self::define('serverId', Server::class, static function (Builder $query, User $user): void {
            if (! $user->is_instance_owner) {
                $query->whereRaw('false');
            }
        });

        // Audit log destinations receive every team's entries (docs/09 R34).
        self::define('auditSink', AuditSink::class, static function (Builder $query, User $user): void {
            if (! $user->is_instance_owner) {
                $query->whereRaw('false');
            }
        });
    }

    /**
     * Rows whose `team_id` is one of the user's teams.
     *
     * @param  array<string, class-string<Model>>  $parameters
     */
    public static function teamOwned(array $parameters): void
    {
        foreach ($parameters as $parameter => $class) {
            self::define($parameter, $class, static function (Builder $query, User $user): void {
                $query->whereIn('team_id', $user->teamIdsQuery());
            });
        }
    }

    /**
     * Team rows, plus the instance-level rows (`team_id` null) for instance
     * owners only (backup destinations and schedules of the control plane).
     *
     * @param  array<string, class-string<Model>>  $parameters
     */
    public static function teamOwnedOrInstance(array $parameters): void
    {
        foreach ($parameters as $parameter => $class) {
            self::define($parameter, $class, static function (Builder $query, User $user): void {
                $query->where(static function (Builder $query) use ($user): void {
                    $query->whereIn('team_id', $user->teamIdsQuery());

                    if ($user->is_instance_owner) {
                        $query->orWhereNull('team_id');
                    }
                });
            });
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  Closure(Builder<TModel>, User): void  $scope  narrows the query to what the user may reach
     */
    public static function define(string $parameter, string $class, Closure $scope): void
    {
        /** @var Closure(Builder<Model>, User): void $scope */
        self::$parameters[$parameter] = [$class, $scope];

        Route::pattern($parameter, PublicId::routePattern(self::prefix($class)));
        Route::bind($parameter, static function (string $value) use ($parameter, $class): Model {
            $user = Auth::user();

            // Bindings on guest routes are a programming error: fail as not found.
            return ($user instanceof User ? self::find($parameter, $value, $user) : null)
                ?? throw (new ModelNotFoundException)->setModel($class);
        });
    }

    /**
     * The row behind a raw public id for a registered parameter, scoped like
     * its route binding; null when malformed, missing or out of reach.
     */
    public static function find(string $parameter, string $value, User $user): ?Model
    {
        [$class, $scope] = self::$parameters[$parameter] ?? throw new LogicException("Route parameter [{$parameter}] is not registered.");

        if (! PublicId::isValid($value, self::prefix($class))) {
            return null;
        }

        $query = $class::query()->where('public_id', $value);
        $scope($query, $user);

        return $query->first();
    }

    /**
     * @param  class-string<Model>  $class
     */
    private static function prefix(string $class): string
    {
        $publicIdPrefix = [$class, 'publicIdPrefix'];
        $prefix = is_callable($publicIdPrefix) ? $publicIdPrefix() : null;

        return is_string($prefix) ? $prefix : throw new LogicException("{$class} has no public id prefix.");
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Routing\Git;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\User;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Team-scoped route bindings for Git resources, same rules as
 * {@see TeamScopedBindings}: the public id must be
 * well-formed and the row must belong to one of the user's teams, else 404.
 */
final class GitRouteBindings
{
    /** @var array<string, class-string<GitHubApp|GitHubInstallation|GitHubRepository|GitConnection>> */
    private const array TEAM_OWNED = [
        'githubApp' => GitHubApp::class,
        'githubInstallation' => GitHubInstallation::class,
        'githubRepository' => GitHubRepository::class,
        'gitConnection' => GitConnection::class,
    ];

    public static function register(): void
    {
        foreach (self::TEAM_OWNED as $parameter => $class) {
            Route::bind($parameter, static function (string $value) use ($class): GitHubApp|GitHubInstallation|GitHubRepository|GitConnection {
                $user = Auth::user();

                if (! $user instanceof User || ! PublicId::isValid($value, $class::publicIdPrefix())) {
                    throw (new ModelNotFoundException)->setModel($class);
                }

                return $class::query()
                    ->where('public_id', $value)
                    ->whereIn('team_id', $user->teamIdsQuery())
                    ->firstOrFail();
            });
        }
    }
}

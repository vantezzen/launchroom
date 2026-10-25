<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\RemoteAccess\RemoteAccess;
use App\Models\User;
use App\Policies\RemoteAccess\RemoteAccessPolicy;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for temporary remote access (D61): the policy, the team-scoped
 * `{remoteAccess}` binding, routes.
 */
final class RemoteAccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(RemoteAccess::class, RemoteAccessPolicy::class);

        Route::bind('remoteAccess', static function (string $value): Model {
            $user = Auth::user();

            if (! $user instanceof User || ! PublicId::isValid($value, RemoteAccess::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(RemoteAccess::class);
            }

            return RemoteAccess::query()
                ->where('public_id', $value)
                ->whereIn('team_id', $user->teamIdsQuery())
                ->firstOrFail();
        });

        $this->loadRoutesFrom(base_path('routes/remote-access.php'));
    }
}

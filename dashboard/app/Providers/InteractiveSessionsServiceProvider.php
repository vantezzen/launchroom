<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Sessions\DatabaseQuery;
use App\Models\Sessions\InteractiveSession;
use App\Models\User;
use App\Policies\Sessions\DatabaseQueryPolicy;
use App\Policies\Sessions\InteractiveSessionPolicy;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for interactive sessions (docs/03 §11): policies, the team-scoped
 * `{interactiveSession}` binding, routes.
 */
final class InteractiveSessionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(InteractiveSession::class, InteractiveSessionPolicy::class);
        Gate::policy(DatabaseQuery::class, DatabaseQueryPolicy::class);

        Route::bind('interactiveSession', static function (string $value): Model {
            $user = Auth::user();

            if (! $user instanceof User || ! PublicId::isValid($value, InteractiveSession::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(InteractiveSession::class);
            }

            return InteractiveSession::query()
                ->where('public_id', $value)
                ->whereIn('team_id', $user->teamIdsQuery())
                ->firstOrFail();
        });

        $this->loadRoutesFrom(base_path('routes/sessions.php'));
    }
}

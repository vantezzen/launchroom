<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Only signed-in users ever reach Horizon (its routes also carry the `auth`
     * middleware). Outside the local environment the user must additionally
     * be an instance owner. Deny by default.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static function (Request $request): bool {
            $user = $request->user();

            if (! $user instanceof User) {
                return false;
            }

            return app()->environment('local') || Gate::forUser($user)->check('viewHorizon');
        });
    }

    protected function gate(): void
    {
        // Operator tooling is for instance owners (users.is_instance_owner).
        Gate::define('viewHorizon', static fn (User $user): bool => $user->is_instance_owner === true);
    }
}

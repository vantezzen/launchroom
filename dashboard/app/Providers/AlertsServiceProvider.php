<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\User;
use App\Policies\Alerts\AlertChannelPolicy;
use App\Policies\Alerts\AlertPolicy;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for alerts and notifications (docs/15 §3): policies, the
 * team-scoped `{alertChannel}` binding, routes.
 */
final class AlertsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AlertChannel::class, AlertChannelPolicy::class);
        Gate::policy(Alert::class, AlertPolicy::class);

        // Like App\Http\Routing\TeamScopedBindings: another team's channel is a 404.
        Route::bind('alertChannel', static function (string $value): AlertChannel {
            $user = Auth::user();

            if (! $user instanceof User || ! PublicId::isValid($value, AlertChannel::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(AlertChannel::class);
            }

            return AlertChannel::query()
                ->where('public_id', $value)
                ->whereIn('team_id', $user->teamIdsQuery())
                ->firstOrFail();
        });

        $this->loadRoutesFrom(base_path('routes/alerts.php'));
    }
}

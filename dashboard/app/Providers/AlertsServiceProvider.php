<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Alerts\AlertChannel;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for alerts and notifications (docs/15 §3): the `{alertChannel}`
 * binding and routes.
 */
final class AlertsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned(['alertChannel' => AlertChannel::class]);

        $this->loadRoutesFrom(base_path('routes/alerts.php'));
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Security\SecurityFinding;
use App\Models\Security\WafExclusion;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the security center (D63, docs/22): the `{finding}` and
 * `{wafExclusion}` bindings and routes.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned([
            'finding' => SecurityFinding::class,
            'wafExclusion' => WafExclusion::class,
        ]);

        $this->loadRoutesFrom(base_path('routes/security.php'));
    }
}

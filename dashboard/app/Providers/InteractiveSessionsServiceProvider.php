<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Sessions\InteractiveSession;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for interactive sessions (docs/03 §11): the `{interactiveSession}`
 * binding and routes.
 */
final class InteractiveSessionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned(['interactiveSession' => InteractiveSession::class]);

        $this->loadRoutesFrom(base_path('routes/sessions.php'));
    }
}

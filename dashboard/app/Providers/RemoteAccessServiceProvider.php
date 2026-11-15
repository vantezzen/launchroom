<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\RemoteAccess\RemoteAccess;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for temporary remote access (D61): the `{remoteAccess}` binding
 * and routes.
 */
final class RemoteAccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned(['remoteAccess' => RemoteAccess::class]);

        $this->loadRoutesFrom(base_path('routes/remote-access.php'));
    }
}

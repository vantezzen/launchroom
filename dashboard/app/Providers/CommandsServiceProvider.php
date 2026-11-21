<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Routing\TeamScopedBindings;
use App\Models\Commands\CommandRun;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for one-off commands (D105): the `{commandRun}` binding and the
 * Commands tab's routes.
 */
final class CommandsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TeamScopedBindings::teamOwned(['commandRun' => CommandRun::class]);

        $this->loadRoutesFrom(base_path('routes/commands.php'));
    }
}

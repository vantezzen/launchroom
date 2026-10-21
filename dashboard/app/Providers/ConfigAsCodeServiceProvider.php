<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Policies\ConfigAsCode\EnvironmentConfigFilePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for config as code (docs/18, D45).
 */
final class ConfigAsCodeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(EnvironmentConfigFile::class, EnvironmentConfigFilePolicy::class);
    }
}

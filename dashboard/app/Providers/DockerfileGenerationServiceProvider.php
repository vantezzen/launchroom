<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DockerfileModel;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Services\Dockerfiles\AnthropicDockerfileModel;
use App\Services\Dockerfiles\StubDockerfileModel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for "Generate a Dockerfile" (docs/20, D48): the model binding
 * (the Claude API, or the stub in local/testing), the `{generation}`
 * binding and the routes.
 */
final class DockerfileGenerationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DockerfileModel::class, static fn (Application $app): DockerfileModel => config('launchroom.dockerfile_generation.model') === 'stub' && $app->environment(['local', 'testing'])
            ? $app->make(StubDockerfileModel::class)
            : $app->make(AnthropicDockerfileModel::class));
    }

    public function boot(): void
    {
        TeamScopedBindings::teamOwned(['generation' => DockerfileGeneration::class]);

        $this->loadRoutesFrom(base_path('routes/dockerfiles.php'));
    }
}

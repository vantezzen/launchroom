<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DockerfileModel;
use App\Models\Dockerfiles\DockerfileGeneration;
use App\Models\User;
use App\Policies\Dockerfiles\DockerfileGenerationPolicy;
use App\Services\Dockerfiles\AnthropicDockerfileModel;
use App\Services\Dockerfiles\StubDockerfileModel;
use App\Support\PublicId;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for "Generate a Dockerfile" (docs/20, D48): the model binding
 * (the Claude API, or the stub in local/testing), the policy, the
 * team-scoped `{generation}` binding and the routes.
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
        Gate::policy(DockerfileGeneration::class, DockerfileGenerationPolicy::class);

        Route::bind('generation', static function (string $value): Model {
            $user = Auth::user();

            if (! $user instanceof User || ! PublicId::isValid($value, DockerfileGeneration::publicIdPrefix())) {
                throw (new ModelNotFoundException)->setModel(DockerfileGeneration::class);
            }

            return DockerfileGeneration::query()
                ->where('public_id', $value)
                ->whereIn('team_id', $user->teamIdsQuery())
                ->firstOrFail();
        });

        $this->loadRoutesFrom(base_path('routes/dockerfiles.php'));
    }
}

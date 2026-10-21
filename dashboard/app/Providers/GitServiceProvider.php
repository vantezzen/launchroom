<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Git\TrackedRefs;
use App\Http\Routing\Git\GitRouteBindings;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Git\GitRepository;
use App\Policies\Git\GitConnectionPolicy;
use App\Policies\Git\GitHubAppPolicy;
use App\Policies\Git\GitHubInstallationPolicy;
use App\Policies\Git\GitHubRepositoryPolicy;
use App\Policies\Git\GitRepositoryPolicy;
use App\Services\GitHub\NullTrackedRefs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the GitHub integration (docs/06 §2, docs/09 R17–R20).
 */
final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The core domain binds its own implementation (services' Git sources
        // × environment triggers); bindIf keeps that one when it exists.
        $this->app->bindIf(TrackedRefs::class, NullTrackedRefs::class);
    }

    public function boot(): void
    {
        Gate::policy(GitHubApp::class, GitHubAppPolicy::class);
        Gate::policy(GitHubInstallation::class, GitHubInstallationPolicy::class);
        Gate::policy(GitHubRepository::class, GitHubRepositoryPolicy::class);
        Gate::policy(GitConnection::class, GitConnectionPolicy::class);
        Gate::policy(GitRepository::class, GitRepositoryPolicy::class);

        GitRouteBindings::register();

        // GitHub delivers from a known set of ranges; this only caps abuse of
        // the public endpoint (unsigned requests are rejected before any work).
        RateLimiter::for('github-webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by((string) $request->ip()));
    }
}

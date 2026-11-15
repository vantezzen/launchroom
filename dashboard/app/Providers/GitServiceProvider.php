<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Git\SshRemotes;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Services\Git\Ssh\PhpseclibSshRemotes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the git providers (docs/06 §2, docs/19, docs/09 R17–R20).
 */
final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SshRemotes::class, PhpseclibSshRemotes::class);
    }

    public function boot(): void
    {
        TeamScopedBindings::teamOwned([
            'githubApp' => GitHubApp::class,
            'githubInstallation' => GitHubInstallation::class,
            'githubRepository' => GitHubRepository::class,
            'gitConnection' => GitConnection::class,
        ]);

        // GitHub delivers from a known set of ranges; this only caps abuse of
        // the public endpoint (unsigned requests are rejected before any work).
        RateLimiter::for('github-webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by((string) $request->ip()));
    }
}

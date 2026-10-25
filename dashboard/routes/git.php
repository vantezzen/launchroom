<?php

declare(strict_types=1);

use App\Http\Controllers\Git\ConnectionWebhookController;
use App\Http\Controllers\Git\GitConnectionController;
use App\Http\Controllers\Git\GitHubAppController;
use App\Http\Controllers\Git\GitHubAppManifestController;
use App\Http\Controllers\Git\GitHubInstallationController;
use App\Http\Controllers\Git\GitHubWebhookController;
use App\Http\Controllers\Git\GitOAuthController;
use App\Http\Controllers\Git\GitRepositoryController;
use App\Http\Controllers\Git\GitServerController;
use App\Http\Controllers\Git\GitSettingsController;
use Illuminate\Support\Facades\Route;

/*
| GitHub integration (docs/06 §2, docs/09 R17–R20). Registered from
| bootstrap/app.php; the dashboard routes get the `web` group here, the
| webhook deliberately doesn't (no session, cookies or CSRF).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/git', [GitSettingsController::class, 'edit'])->name('git.edit');

    // Manifest flow: start → GitHub → callback.
    Route::post('settings/git/github/manifest', [GitHubAppManifestController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('git.github.manifest.store');
    Route::get('settings/git/github/callback', [GitHubAppManifestController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('git.github.manifest.callback');
    // GitHub's "setup URL" after installing the app.
    Route::get('settings/git/github/setup', [GitHubInstallationController::class, 'setup'])
        ->middleware('throttle:20,1')
        ->name('git.github.setup');
    Route::delete('settings/git/github/{githubApp}', [GitHubAppController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('git.github.destroy');
    Route::post('settings/git/installations/{githubInstallation}/refresh', [GitHubInstallationController::class, 'refresh'])
        ->middleware('throttle:10,1')
        ->name('git.installations.refresh');

    // GitLab / Gitea / Forgejo / any git server (docs/19 §4).
    Route::post('settings/git/connections', [GitConnectionController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('git.connections.store');
    Route::delete('settings/git/connections/{gitConnection}', [GitConnectionController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('git.connections.destroy');
    Route::get('settings/git/oauth/callback', GitOAuthController::class)
        ->middleware('throttle:20,1')
        ->name('git.oauth.callback');
    Route::post('settings/git/servers', [GitServerController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('git.servers.store');
    Route::post('settings/git/servers/{gitConnection}/confirm', [GitServerController::class, 'confirm'])
        ->middleware('throttle:20,1')
        ->name('git.servers.confirm');
    // The generic webhook's URL and secret (JSON, owners/admins, audited).
    Route::post('settings/git/servers/{gitConnection}/webhook', [GitServerController::class, 'webhook'])
        ->middleware('throttle:10,1')
        ->name('git.servers.webhook');
    Route::post('settings/git/servers/{gitConnection}/recheck', [GitServerController::class, 'recheck'])
        ->middleware('throttle:10,1')
        ->name('git.servers.recheck');

    // Repository picker (JSON).
    Route::get('git/repositories', [GitRepositoryController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('git.repositories.index');
    Route::get('git/repositories/{repository}/branches', [GitRepositoryController::class, 'branches'])
        ->middleware('throttle:30,1')
        ->name('git.repositories.branches');
});

// Public: authenticated by the connection's secret (fail closed, docs/19 §5).
Route::post('webhooks/gitlab/{connection}', [ConnectionWebhookController::class, 'gitlab'])
    ->middleware('throttle:github-webhooks')
    ->name('git.webhooks.gitlab');
Route::post('webhooks/gitea/{connection}', [ConnectionWebhookController::class, 'gitea'])
    ->middleware('throttle:github-webhooks')
    ->name('git.webhooks.gitea');
Route::post('webhooks/git/{connection}', [ConnectionWebhookController::class, 'git'])
    ->middleware('throttle:github-webhooks')
    ->name('git.webhooks.git');

// Public: authenticated by the X-Hub-Signature-256 HMAC (fail closed).
Route::post('webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:github-webhooks')
    ->name('git.github.webhook');

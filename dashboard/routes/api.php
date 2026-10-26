<?php

declare(strict_types=1);

use App\Enums\ApiAbility;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\CliSessionController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DeploymentController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\EnvironmentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServiceActionController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\VariableController;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequireTokenAbility;
use App\Support\PublicId;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| Public API v1 (docs/16 §2). Registered under /api/v1 from bootstrap/app.php,
| outside the web group: no session, no cookies, no CSRF. Bearer tokens only
| (Sanctum, team-bound, expiring). Every route declares the ability it needs;
| controllers authorize with the same policies as the dashboard, whose
| permission checks are narrowed by the token (User::hasTeamPermission).
*/

$ability = static fn (ApiAbility $ability): string => RequireTokenAbility::class.':'.$ability->value;

Route::middleware(SubstituteBindings::class)->group(function () use ($ability): void {
    // Public: the OpenAPI document and the CLI sign-in (docs/16 §4).
    Route::get('openapi.json', OpenApiController::class)
        ->middleware('throttle:60,1')
        ->name('api.openapi');
    Route::post('cli/sessions', [CliSessionController::class, 'store'])
        ->middleware('throttle:api-cli-start')
        ->name('api.cli.sessions.store');
    Route::post('cli/sessions/token', [CliSessionController::class, 'token'])
        ->middleware('throttle:api-cli-poll')
        ->name('api.cli.sessions.token');

    Route::middleware(['auth:sanctum', 'throttle:api'])
        ->where([
            'project' => PublicId::routePattern('prj'),
            'environment' => PublicId::routePattern('env'),
            'service' => PublicId::routePattern('svc'),
            'deployment' => PublicId::routePattern('dpl'),
            'variable' => PublicId::routePattern('var'),
            'domain' => PublicId::routePattern('dom'),
            'server' => PublicId::routePattern('srv'),
            'transfer' => PublicId::routePattern('dtr'),
            'import' => PublicId::routePattern('imp'),
            'export' => PublicId::routePattern('exp'),
            'finding' => PublicId::routePattern('fnd'),
        ])
        ->name('api.')
        ->group(function () use ($ability): void {
            Route::get('me', MeController::class)->name('me');

            Route::middleware($ability(ApiAbility::Read))->group(function (): void {
                Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
                Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
                Route::get('projects/{project}/environments', [ProjectController::class, 'environments'])->name('projects.environments');
                Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');
                Route::get('environments/{environment}/services', [EnvironmentController::class, 'services'])->name('services.index');
                Route::get('environments/{environment}/services/{service}', [EnvironmentController::class, 'service'])->name('services.show');
                Route::get('environments/{environment}/deployments', [DeploymentController::class, 'index'])->name('deployments.index');
                Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
                Route::get('deployments/{deployment}/logs', [LogController::class, 'deployment'])->name('deployments.logs');
                Route::get('deployments/{deployment}/logs/stream', [LogController::class, 'deploymentStream'])->name('deployments.logs.stream');
                Route::get('environments/{environment}/logs', [LogController::class, 'environment'])->name('environments.logs');
                Route::get('environments/{environment}/logs/stream', [LogController::class, 'environmentStream'])->name('environments.logs.stream');
                Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
                Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
                Route::get('environments/{environment}/services/{service}/domains', [DomainController::class, 'index'])->name('domains.index');
                // Request analytics (docs/15 §5.4).
                Route::get('analytics', [AnalyticsController::class, 'overview'])->name('analytics.team');
                Route::get('environments/{environment}/analytics', [AnalyticsController::class, 'environment'])->name('analytics.environment');
                Route::get('environments/{environment}/services/{service}/analytics', [AnalyticsController::class, 'service'])->name('analytics.service');
                // Security center (docs/22 §11): read-only.
                Route::get('security/findings', [SecurityController::class, 'findings'])->name('security.findings.index');
                Route::get('security/findings/{finding}', [SecurityController::class, 'finding'])->name('security.findings.show');
                Route::get('security/inventory', [SecurityController::class, 'inventory'])->name('security.inventory');
                Route::get('security/waf', [SecurityController::class, 'waf'])->name('security.waf');
                Route::get('environments/{environment}/services/{service}/inventory', [SecurityController::class, 'serviceInventory'])->name('security.service-inventory');
                // launchroom.json (docs/18 §7): checks are dry runs, nothing is stored.
                Route::get('environments/{environment}/config/status', [ConfigController::class, 'status'])->name('config.status');
                Route::post('environments/{environment}/config/validate', [ConfigController::class, 'check'])->middleware('throttle:api-write')->name('config.validate');
                Route::post('environments/{environment}/config/diff', [ConfigController::class, 'diff'])->middleware('throttle:api-write')->name('config.diff');
            });

            Route::middleware([$ability(ApiAbility::Deploy), 'throttle:api-write', IdempotentRequest::class])->group(function (): void {
                Route::post('environments/{environment}/deployments', [DeploymentController::class, 'store'])->name('deployments.store');
                Route::post('environments/{environment}/deploy', [DeploymentController::class, 'deployEnvironment'])->name('environments.deploy');
                Route::post('deployments/{deployment}/redeploy', [DeploymentController::class, 'redeploy'])->name('deployments.redeploy');
                Route::post('deployments/{deployment}/rollback', [DeploymentController::class, 'rollback'])->name('deployments.rollback');
                Route::post('deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
                // Service lifecycle (D68): the service panel header's actions.
                Route::post('environments/{environment}/services/{service}/deploy', [ServiceActionController::class, 'deploy'])->name('services.deploy');
                Route::post('environments/{environment}/services/{service}/restart', [ServiceActionController::class, 'restart'])->name('services.restart');
                Route::post('environments/{environment}/services/{service}/stop', [ServiceActionController::class, 'stop'])->name('services.stop');
                Route::post('environments/{environment}/services/{service}/start', [ServiceActionController::class, 'start'])->name('services.start');
            });

            Route::middleware($ability(ApiAbility::EnvRead))->group(function (): void {
                Route::get('environments/{environment}/services/{service}/variables', [VariableController::class, 'index'])->name('variables.index');
                Route::get('environments/{environment}/variables/{variable}/value', [VariableController::class, 'value'])->name('variables.value');
                Route::get('environments/{environment}/services/{service}/env', [VariableController::class, 'env'])->name('variables.env');
                Route::get('environments/{environment}/config', [ConfigController::class, 'export'])->name('config.export');
            });

            Route::middleware([$ability(ApiAbility::EnvWrite), 'throttle:api-write', IdempotentRequest::class])->group(function (): void {
                Route::post('environments/{environment}/services/{service}/variables', [VariableController::class, 'store'])->name('variables.store');
                Route::patch('environments/{environment}/variables/{variable}', [VariableController::class, 'update'])->name('variables.update');
                Route::delete('environments/{environment}/variables/{variable}', [VariableController::class, 'destroy'])->name('variables.destroy');
            });

            // Data import and export (docs/14 §12.7): the opt-in data:transfer ability.
            Route::middleware([$ability(ApiAbility::DataTransfer)])->group(function (): void {
                Route::get('transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');
                Route::put('transfers/{transfer}/chunks/{index}', [TransferController::class, 'chunk'])->whereNumber('index')->middleware('throttle:transfer-chunks')->name('transfers.chunks');
                Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
                Route::get('exports/{export}', [ExportController::class, 'show'])->name('exports.show');
                Route::get('downloads/{token}', DownloadController::class)->where('token', 'lr_dl_[A-Za-z0-9_-]{43}')->name('downloads.show');

                Route::middleware(['throttle:api-write', IdempotentRequest::class])->group(function (): void {
                    Route::post('environments/{environment}/services/{service}/transfers', [TransferController::class, 'store'])->name('transfers.store');
                    Route::post('transfers/{transfer}/complete', [TransferController::class, 'complete'])->name('transfers.complete');
                    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->name('transfers.destroy');
                    Route::post('environments/{environment}/services/{service}/imports', [ImportController::class, 'store'])->name('imports.store');
                    Route::post('environments/{environment}/services/{service}/exports', [ExportController::class, 'store'])->name('exports.store');
                    Route::post('exports/{export}/link', [ExportController::class, 'link'])->name('exports.link');
                });
            });

            Route::middleware([$ability(ApiAbility::DomainsWrite), 'throttle:api-write', IdempotentRequest::class])->group(function (): void {
                Route::post('environments/{environment}/services/{service}/domains', [DomainController::class, 'store'])->name('domains.store');
                Route::delete('environments/{environment}/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
            });
        });
});

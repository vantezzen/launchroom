<?php

declare(strict_types=1);

use App\Http\Controllers\Projects\CanvasController;
use App\Http\Controllers\Projects\ConfigFileController;
use App\Http\Controllers\Projects\ConnectionController;
use App\Http\Controllers\Projects\DomainController;
use App\Http\Controllers\Projects\ImportController;
use App\Http\Controllers\Projects\LogRetentionController;
use App\Http\Controllers\Projects\PlanPreviewController;
use App\Http\Controllers\Projects\ProjectAnalyticsController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectIconController;
use App\Http\Controllers\Projects\ProjectLogsController;
use App\Http\Controllers\Projects\ProjectMetricsController;
use App\Http\Controllers\Projects\ProjectMetricsPageController;
use App\Http\Controllers\Projects\ProjectSettingsController;
use App\Http\Controllers\Projects\ServiceController;
use App\Http\Controllers\Projects\ServiceSettingsController;
use App\Support\PublicId;
use Illuminate\Support\Facades\Route;

/*
| Projects, the canvas and the service panel (docs/08 §2). Registered from
| bootstrap/app.php. Nested ids are constrained to their grammar so that
| `/p/{project}/settings` or `/p/{project}/deployments` never match
| `{environment}`; controllers additionally check that nested resources
| belong together (ProjectScope).
*/

Route::middleware(['web', 'auth', 'verified'])
    ->where([
        'project' => PublicId::routePattern('prj'),
        'environment' => PublicId::routePattern('env'),
        'service' => PublicId::routePattern('svc'),
        'domain' => PublicId::routePattern('dom'),
        'connection' => PublicId::routePattern('con'),
    ])
    ->group(function (): void {
        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        // "Import a repository", the guided new-project flow.
        Route::get('projects/new', [ImportController::class, 'create'])->name('projects.import');
        Route::get('projects/-/inspect', [ImportController::class, 'inspect'])
            ->middleware('throttle:30,1')
            ->name('projects.import.inspect');
        Route::get('projects/-/plan-preview', [ImportController::class, 'planPreview'])
            ->middleware('throttle:60,1')
            ->name('projects.import.plan-preview');
        Route::post('projects/import', [ImportController::class, 'store'])->name('projects.import.store');
        Route::get('p/{project}', [ProjectController::class, 'show'])->name('projects.show');
        // Per-service usage metrics JSON (canvas nodes, service panel Metrics tab).
        Route::get('p/{project}/-/metrics', ProjectMetricsController::class)->name('projects.metrics');
        // Request analytics JSON (docs/15 §5.4).
        Route::get('p/{project}/-/analytics', [ProjectAnalyticsController::class, 'data'])
            ->middleware('throttle:120,1')
            ->name('projects.analytics');

        Route::get('p/{project}/settings/{section?}', [ProjectSettingsController::class, 'show'])
            ->whereIn('section', ProjectSettingsController::SECTIONS)
            ->name('projects.settings');
        Route::patch('p/{project}/settings', [ProjectSettingsController::class, 'update'])->name('projects.settings.update');
        Route::delete('p/{project}', [ProjectSettingsController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('projects.destroy');
        Route::post('p/{project}/environments', [ProjectSettingsController::class, 'storeEnvironment'])->name('projects.environments.store');

        // Config as code (docs/18): where launchroom.json is read from, "Check now", download.
        Route::patch('p/{project}/settings/config-file', [ConfigFileController::class, 'update'])->name('projects.config.update');
        Route::post('p/{project}/{environment}/-/config/sync', [ConfigFileController::class, 'sync'])
            ->middleware('throttle:10,1')
            ->name('projects.config.sync');
        Route::get('p/{project}/{environment}/-/config', [ConfigFileController::class, 'download'])
            ->middleware('throttle:30,1')
            ->name('projects.config.download');

        // Project icons (docs/08 §13): the stored file for members, upload and reset.
        Route::get('p/{project}/icon', [ProjectIconController::class, 'show'])->name('projects.icon');
        Route::post('p/{project}/icon', [ProjectIconController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('projects.icon.store');
        Route::delete('p/{project}/icon', [ProjectIconController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('projects.icon.destroy');

        Route::get('p/{project}/plan-preview', PlanPreviewController::class)
            ->middleware('throttle:30,1')
            ->name('projects.plan-preview');

        // Search across every environment of the project (JSON).
        Route::get('p/{project}/-/logs', [ProjectLogsController::class, 'projectEntries'])
            ->middleware('throttle:60,1')
            ->name('projects.logs.search');

        Route::get('p/{project}/{environment}', [CanvasController::class, 'show'])->name('projects.canvas');
        // The project Logs tab and the log explorer behind it.
        Route::get('p/{project}/{environment}/logs', [ProjectLogsController::class, 'show'])->name('projects.logs');
        // The project Metrics tab (docs/15 §2.3); numbers come from projects.metrics.
        Route::get('p/{project}/{environment}/metrics', [ProjectMetricsPageController::class, 'show'])->name('projects.metrics-page');
        // The project Analytics tab (docs/15 §5.4); numbers come from projects.analytics.
        Route::get('p/{project}/{environment}/analytics', [ProjectAnalyticsController::class, 'show'])->name('projects.analytics-page');
        // The log explorer's lines and live runtime tails (JSON).
        Route::get('p/{project}/{environment}/-/logs', [ProjectLogsController::class, 'entries'])
            ->middleware('throttle:120,1')
            ->name('projects.logs.entries');
        Route::post('p/{project}/{environment}/-/logs/tail', [ProjectLogsController::class, 'startTail'])
            ->middleware('throttle:20,1')
            ->name('projects.logs.tail');
        Route::delete('p/{project}/{environment}/-/logs/tail', [ProjectLogsController::class, 'stopTail'])
            ->middleware('throttle:20,1')
            ->name('projects.logs.tail.stop');
        // Runtime log retention of an environment (opt-in).
        Route::patch('p/{project}/{environment}/log-retention', LogRetentionController::class)
            ->name('projects.log-retention');
        Route::put('p/{project}/{environment}/layout', [CanvasController::class, 'layout'])
            ->middleware('throttle:120,1')
            ->name('projects.layout');

        Route::post('p/{project}/{environment}/services', [ServiceController::class, 'store'])->name('projects.services.store');
        Route::get('p/{project}/{environment}/s/{service}/{tab}', [CanvasController::class, 'movedTab'])
            ->whereIn('tab', CanvasController::MOVED_TABS)
            ->name('projects.services.moved');
        Route::get('p/{project}/{environment}/s/{service}/{tab?}', [CanvasController::class, 'service'])
            ->whereIn('tab', CanvasController::TABS)
            ->name('projects.services.show');
        Route::get('p/{project}/{environment}/s/{service}/settings/{section}', [CanvasController::class, 'settingsSection'])
            ->whereIn('section', CanvasController::SETTINGS_SECTIONS)
            ->name('projects.services.section');
        Route::patch('p/{project}/{environment}/s/{service}/settings', [ServiceSettingsController::class, 'update'])->name('projects.services.settings');
        Route::post('p/{project}/{environment}/s/{service}/scaling/preview', [ServiceSettingsController::class, 'previewScaling'])
            ->middleware('throttle:120,1')
            ->name('projects.services.scaling-preview');
        Route::patch('p/{project}/{environment}/s/{service}/name', [ServiceSettingsController::class, 'rename'])->name('projects.services.rename');
        Route::post('p/{project}/{environment}/s/{service}/volumes', [ServiceSettingsController::class, 'addVolume'])->name('projects.services.volumes.store');
        Route::patch('p/{project}/{environment}/s/{service}/volumes/{volume}', [ServiceSettingsController::class, 'updateVolume'])
            ->where('volume', 'vol_[0-9A-HJKMNP-TV-Z]{26}')
            ->name('projects.services.volumes.update');
        Route::delete('p/{project}/{environment}/s/{service}/volumes/{volume}', [ServiceSettingsController::class, 'removeVolume'])
            ->where('volume', 'vol_[0-9A-HJKMNP-TV-Z]{26}')
            ->name('projects.services.volumes.destroy');
        Route::patch('p/{project}/{environment}/s/{service}/image', [ServiceSettingsController::class, 'changeImage'])->name('projects.services.image');
        Route::post('p/{project}/{environment}/s/{service}/plan', [ServiceSettingsController::class, 'replan'])
            ->middleware('throttle:10,1')
            ->name('projects.services.replan');
        Route::delete('p/{project}/{environment}/s/{service}', [ServiceController::class, 'destroy'])->name('projects.services.destroy');

        Route::post('p/{project}/{environment}/s/{service}/domains', [DomainController::class, 'store'])->name('projects.domains.store');
        Route::patch('p/{project}/{environment}/s/{service}/domains/{domain}', [DomainController::class, 'update'])->name('projects.domains.update');
        Route::post('p/{project}/{environment}/s/{service}/domains/{domain}/verify', [DomainController::class, 'verify'])
            ->middleware('throttle:20,1')
            ->name('projects.domains.verify');
        Route::delete('p/{project}/{environment}/s/{service}/domains/{domain}', [DomainController::class, 'destroy'])->name('projects.domains.destroy');

        Route::get('p/{project}/{environment}/connections/preview', [ConnectionController::class, 'preview'])
            ->middleware('throttle:120,1')
            ->name('projects.connections.preview');
        Route::post('p/{project}/{environment}/connections', [ConnectionController::class, 'store'])->name('projects.connections.store');
        Route::delete('p/{project}/{environment}/connections/{connection}', [ConnectionController::class, 'destroy'])->name('projects.connections.destroy');
    });

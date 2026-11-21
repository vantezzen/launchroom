<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Data\Canvas\EnvironmentLiveStatusData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Queries\EnvironmentLiveStatus;
use Illuminate\Support\Facades\Gate;

/**
 * GET /p/{project}/{environment}/-/status (D108, docs/08 §23): every service
 * status of the environment, which the live-status store refetches on a
 * `service.status` or `canvas.changed` nudge, a reconnect or a visible tab.
 */
final class LiveStatusController extends Controller
{
    public function __invoke(Project $project, Environment $environment, EnvironmentLiveStatus $status): EnvironmentLiveStatusData
    {
        Gate::authorize('view', $environment);

        return $status->for($environment);
    }
}

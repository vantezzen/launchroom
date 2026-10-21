<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Logs\UpdateRuntimeLogRetention;
use App\Data\Logs\RuntimeLogRetentionData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * `PATCH /p/{project}/{environment}/log-retention` (project settings → Logs):
 * keep the environment's runtime logs for a period and up to a size, or stop.
 */
final class LogRetentionController extends Controller
{
    public function __invoke(Request $request, Project $project, Environment $environment, RuntimeLogRetentionData $data, #[CurrentUser] User $user, UpdateRuntimeLogRetention $update): RedirectResponse
    {
        ProjectScope::environment($project, $environment);
        Gate::authorize('update', $environment);

        $update->handle($environment, $data, $this->auditActor($request, $user));

        Inertia::flash('toast', ['type' => 'success', 'message' => $data->enabled
            ? "Runtime logs of {$environment->name} are kept for {$data->days} ".($data->days === 1 ? 'day' : 'days').'.'
            : "Runtime logs of {$environment->name} are no longer kept."]);

        return back();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Actions\Deployments\DeployLatest;
use App\Actions\Services\RestartService;
use App\Actions\Services\StartService;
use App\Actions\Services\StopService;
use App\Data\Services\ServiceLifecycleData;
use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Service panel → Deploy, Restart, Stop and Start (D68, docs/08 §13). Each
 * needs the environment's `deploy` permission, is audited by its action and
 * changes only desired state (no command runs on a server).
 */
final class ServiceLifecycleController extends Controller
{
    use ScopesProjectRoutes;

    public function deploy(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, DeployLatest $deploy): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $created = $deploy->handle($instance, $user, $this->auditActor($request, $user));

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }

    public function restart(Request $request, Project $project, Environment $environment, Service $service, ServiceLifecycleData $data, #[CurrentUser] User $user, RestartService $restart): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $restart->handle($instance, $data->processes, $user, $this->auditActor($request, $user));

        return back();
    }

    public function stop(Request $request, Project $project, Environment $environment, Service $service, ServiceLifecycleData $data, #[CurrentUser] User $user, StopService $stop): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $stop->handle($instance, $data->processes, $user, $this->auditActor($request, $user));

        return back();
    }

    public function start(Request $request, Project $project, Environment $environment, Service $service, ServiceLifecycleData $data, #[CurrentUser] User $user, StartService $start): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $start->handle($instance, $data->processes, $user, $this->auditActor($request, $user));

        return back();
    }
}

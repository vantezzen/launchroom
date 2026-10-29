<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployments;

use App\Actions\Deployments\RedeployService;
use App\Data\Deployments\DeploymentStepData;
use App\Data\Deployments\DeploymentSummaryData;
use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\DeploymentSummaries;
use App\Services\Deployments\ReleaseStep;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Service panel → Deployments (docs/08 §3.4): the active deployment and the
 * history of the service in one environment, plus "Redeploy". `current` is
 * the list's expanded first row (the deployment in progress, else the live
 * one, else the newest: a failed or cancelled first deploy stays readable)
 * with its recorded steps and whether it shows a Release step.
 */
final class ServiceDeploymentsController extends Controller
{
    use ScopesProjectRoutes;

    private const int HISTORY = 50;

    public function index(Project $project, Environment $environment, Service $service, DeploymentSummaries $summaries): JsonResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('view', $instance);

        ['active' => $active, 'rows' => $rows] = $summaries->forInstance($instance, self::HISTORY);
        $running = [DeploymentStatus::Queued, ...DeploymentStatus::inFlight()];
        $current = array_values(array_filter($rows, static fn (DeploymentSummaryData $row): bool => in_array($row->status, $running, true)))[0] ?? $active ?? $rows[0] ?? null;
        $deployment = $current === null ? null : Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->where('public_id', $current->id)
            ->first();
        $steps = $deployment === null ? [] : array_values($deployment->steps()->orderBy('id')->get()
            ->map(static fn (DeploymentStep $step): DeploymentStepData => DeploymentStepData::fromStep($step))
            ->all());

        return response()->json([
            'active' => $active,
            'deployments' => $rows,
            'current' => $current === null ? null : [
                'id' => $current->id,
                'steps' => $steps,
                'expectsRelease' => $deployment !== null && ReleaseStep::expected($deployment),
            ],
            // A stopped service starts from its header (D68), not with Redeploy.
            'canDeploy' => $instance->stopped_at === null && Gate::allows('deploy', $environment),
        ]);
    }

    public function redeploy(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, RedeployService $redeploy): RedirectResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('deploy', $environment);

        $created = $redeploy->handle($instance, null, $user, $this->auditActor($request, $user));

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $created->public_id]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\RenameService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Services\AddVolumeData;
use App\Data\Services\PreviewScalingData;
use App\Data\Services\RenameServiceData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ServiceKind;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Jobs\PlanServiceJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Queries\ServiceSettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Settings tab saves: build, deploy, scaling, branch, volumes (staged, docs/06 §4),
 * the service's name, and re-running the plan preview.
 */
final class ServiceSettingsController extends Controller
{
    public function update(Project $project, Environment $environment, Service $service, UpdateServiceSettingsData $data, UpdateServiceSettings $updateSettings): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $instance);
        abort_if($instance->removed_at !== null, 409, 'This service is staged for removal.');

        $updateSettings->handle($instance, $data);

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings saved. Deploy to apply them.']);

        return back();
    }

    /**
     * What the scheduler would do with draft scaling settings (read-only).
     */
    public function previewScaling(Project $project, Environment $environment, Service $service, PreviewScalingData $data, ServiceSettings $settings): JsonResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);

        return response()->json($settings->preview($instance, $data->scaling));
    }

    /**
     * Add a volume to an app or image service (staged; docs/14 §2).
     */
    public function addVolume(Request $request, Project $project, Environment $environment, Service $service, AddVolumeData $data, #[CurrentUser] User $user, AddServiceVolume $addVolume): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $instance);
        abort_if($instance->removed_at !== null, 409, 'This service is staged for removal.');

        $volume = $addVolume->handle($instance, $data, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Volume {$volume->mount_path} added. Deploy to create it."]);

        return back();
    }

    /**
     * Rename (project-wide; the slug stays). Shows as a staged change.
     */
    public function rename(Request $request, Project $project, Environment $environment, Service $service, RenameServiceData $data, #[CurrentUser] User $user, RenameService $renameService): RedirectResponse
    {
        ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $service);

        $renameService->handle($service, $data, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Renamed to {$service->name}."]);

        return back();
    }

    /**
     * Run the plan preview again for the branch head (docs/04 §4).
     */
    public function replan(Project $project, Environment $environment, Service $service): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('update', $instance);
        abort_if($instance->removed_at !== null || $service->kind !== ServiceKind::App, 409, 'Only app services are planned.');

        dispatch(new PlanServiceJob($instance->id));

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Detecting the build plan again.']);

        return back();
    }
}

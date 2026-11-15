<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Services\AddServiceVolume;
use App\Actions\Services\ChangeServiceImage;
use App\Actions\Services\RemoveServiceVolume;
use App\Actions\Services\RenameService;
use App\Actions\Services\ReplanService;
use App\Actions\Services\UpdateServiceSettings;
use App\Actions\Services\UpdateServiceVolume;
use App\Data\Services\AddVolumeData;
use App\Data\Services\ChangeImageData;
use App\Data\Services\PreviewScalingData;
use App\Data\Services\RemoveVolumeData;
use App\Data\Services\RenameServiceData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Data\Services\UpdateVolumeData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Volume;
use App\Queries\ServiceSettings;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Settings tab saves: build, deploy, scaling, branch, volumes (staged, docs/06 §4),
 * the service's name, and re-running the plan preview.
 */
final class ServiceSettingsController extends Controller
{
    public function update(Project $project, Environment $environment, Service $service, UpdateServiceSettingsData $data, UpdateServiceSettings $updateSettings): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('update', $instance);

        $updateSettings->handle($instance, $data);

        Toast::success('Settings saved. Deploy to apply them.');

        return back();
    }

    /**
     * What the scheduler would do with draft scaling settings (read-only).
     */
    public function previewScaling(Project $project, Environment $environment, Service $service, PreviewScalingData $data, ServiceSettings $settings): JsonResponse
    {
        $instance = RouteScope::instance($project, $environment, $service);
        Gate::authorize('view', $instance);

        return response()->json($settings->preview($instance, $data->scaling));
    }

    /**
     * Add a volume to an app or image service (staged; docs/14 §2).
     */
    public function addVolume(Request $request, Project $project, Environment $environment, Service $service, AddVolumeData $data, #[CurrentUser] User $user, AddServiceVolume $addVolume): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('update', $instance);

        $volume = $addVolume->handle($instance, $data, $this->auditActor($request, $user));

        Toast::success("Volume {$volume->mount_path} added. Deploy to create it.");

        return back();
    }

    /**
     * Mount a volume at another path (staged; docs/14 §9.12).
     */
    public function updateVolume(Request $request, Project $project, Environment $environment, Service $service, Volume $volume, UpdateVolumeData $data, #[CurrentUser] User $user, UpdateServiceVolume $updateVolume): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('update', $instance);
        RouteScope::volume($instance, $volume);

        $updated = $updateVolume->handle($instance, $volume, $data, $this->auditActor($request, $user));

        Toast::success("Volume moves to {$updated->mount_path} with the next deploy.");

        return back();
    }

    /**
     * Remove a volume (staged when it holds files; docs/14 §9.12).
     */
    public function removeVolume(Request $request, Project $project, Environment $environment, Service $service, Volume $volume, RemoveVolumeData $data, #[CurrentUser] User $user, RemoveServiceVolume $removeVolume): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('update', $instance);
        RouteScope::volume($instance, $volume);

        $staged = $removeVolume->handle($instance, $volume, $data, $this->auditActor($request, $user));

        Toast::success($staged
            ? "Volume {$volume->mount_path} is removed with the next deploy."
            : "Volume {$volume->mount_path} removed.");

        return back();
    }

    /**
     * Run another image (project-wide like the name; staged, docs/04 §1).
     */
    public function changeImage(Request $request, Project $project, Environment $environment, Service $service, ChangeImageData $data, #[CurrentUser] User $user, ChangeServiceImage $changeImage): RedirectResponse
    {
        RouteScope::instance($project, $environment, $service);
        Gate::authorize('update', $service);

        $changeImage->handle($service, $data, $this->auditActor($request, $user));

        Toast::success('Image changed. Deploy to run it.');

        return back();
    }

    /**
     * Rename (project-wide; the slug stays). Shows as a staged change.
     */
    public function rename(Request $request, Project $project, Environment $environment, Service $service, RenameServiceData $data, #[CurrentUser] User $user, RenameService $renameService): RedirectResponse
    {
        RouteScope::instance($project, $environment, $service);
        Gate::authorize('update', $service);

        $renameService->handle($service, $data, $this->auditActor($request, $user));

        Toast::success("Renamed to {$service->name}.");

        return back();
    }

    public function replan(Project $project, Environment $environment, Service $service, ReplanService $replan): RedirectResponse
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('update', $instance);

        $replan->handle($instance);

        Toast::info('Detecting the build plan again.');

        return back();
    }
}

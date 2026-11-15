<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Services\AttachServiceVolume;
use App\Actions\Services\DetachServiceVolume;
use App\Data\Services\AttachVolumeData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Http\Toast;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Models\Volume;
use App\Services\Volumes\VolumeSharing;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared volumes on the canvas (D73): drag from a service onto a volume to
 * mount it there too, at the service's own path, or detach it again. Both
 * are staged and apply with the next deploy. `{service}` is the service
 * that mounts the volume; the volume belongs to another service of the
 * same environment.
 */
final class VolumeMountController extends Controller
{
    public function preview(Request $request, Project $project, Environment $environment, Service $service, Volume $volume, VolumeSharing $sharing): JsonResponse
    {
        $instance = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $path = $request->query('mountPath');

        return new JsonResponse($sharing->preview($instance, $volume, is_string($path) && $path !== '' ? $path : null));
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, Volume $volume, AttachVolumeData $data, #[CurrentUser] User $user, AttachServiceVolume $attach): RedirectResponse
    {
        $instance = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $attach->handle($instance, $volume, $data, $this->auditActor($request, $user));

        Toast::success("{$service->name} mounts the volume at {$data->mountPath} with the next deploy.");

        return back();
    }

    public function destroy(Request $request, Project $project, Environment $environment, Service $service, Volume $volume, #[CurrentUser] User $user, DetachServiceVolume $detach): RedirectResponse
    {
        $instance = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $detach->handle($instance, $volume, $this->auditActor($request, $user));

        Toast::success("{$service->name} stops using the volume with the next deploy.");

        return back();
    }

    private function resolve(Project $project, Environment $environment, Service $service, Volume $volume): ServiceInstance
    {
        $instance = RouteScope::editable(RouteScope::instance($project, $environment, $service));
        RouteScope::environmentVolume($environment, $volume);
        Gate::authorize('view', $volume);

        return $instance;
    }
}

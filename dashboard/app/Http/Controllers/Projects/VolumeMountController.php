<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Services\AttachServiceVolume;
use App\Actions\Services\DetachServiceVolume;
use App\Data\Services\AttachVolumeData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
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
use Inertia\Inertia;

/**
 * Shared volumes on the canvas (D73): drag from a service onto a volume to
 * mount it there too, at the service's own path, or detach it again. Both
 * are staged and apply with the next deploy. `{service}` is the service
 * that mounts the volume; the volume belongs to another service of the
 * same environment.
 */
final class VolumeMountController extends Controller
{
    public function preview(Request $request, Project $project, Environment $environment, Service $service, string $volume, VolumeSharing $sharing): JsonResponse
    {
        [$instance, $model] = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $path = $request->query('mountPath');

        return new JsonResponse($sharing->preview($instance, $model, is_string($path) && $path !== '' ? $path : null));
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, string $volume, AttachVolumeData $data, #[CurrentUser] User $user, AttachServiceVolume $attach): RedirectResponse
    {
        [$instance, $model] = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $attach->handle($instance, $model, $data, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$service->name} mounts the volume at {$data->mountPath} with the next deploy."]);

        return back();
    }

    public function destroy(Request $request, Project $project, Environment $environment, Service $service, string $volume, #[CurrentUser] User $user, DetachServiceVolume $detach): RedirectResponse
    {
        [$instance, $model] = $this->resolve($project, $environment, $service, $volume);
        Gate::authorize('update', $instance);

        $detach->handle($instance, $model, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$service->name} stops using the volume with the next deploy."]);

        return back();
    }

    /**
     * The service's instance in this environment and a volume of the same
     * environment, both through the project (never a bare lookup by id).
     *
     * @return array{ServiceInstance, Volume}
     */
    private function resolve(Project $project, Environment $environment, Service $service, string $volume): array
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        abort_if($instance->removed_at !== null, 409, 'This service is staged for removal.');

        $model = Volume::query()
            ->where('team_id', $project->team_id)
            ->where('public_id', $volume)
            ->whereIn('service_instance_id', ServiceInstance::query()->where('environment_id', $environment->id)->select('id'))
            ->firstOrFail();
        Gate::authorize('view', $model);

        return [$instance, $model];
    }
}

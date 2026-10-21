<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Canvas\AddService;
use App\Actions\Services\RemoveService;
use App\Data\Canvas\AddServiceData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Adding a service from the canvas, and staging its removal.
 */
final class ServiceController extends Controller
{
    public function store(Request $request, Project $project, Environment $environment, AddServiceData $data, #[CurrentUser] User $user, AddService $addService): RedirectResponse
    {
        Gate::authorize('create', [Service::class, $project]);
        ProjectScope::environment($project, $environment);

        if (($data->connectTo ?? []) !== []) {
            Gate::authorize('create', [Connection::class, $project]);
        }

        $service = $addService->handle($project, $environment, $data, $user, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => ($data->connectTo ?? []) === []
            ? "{$service->name} added. Deploy to start it."
            : "{$service->name} added and connected. Deploy to start it."]);

        return to_route('projects.services.show', [$project, $environment, $service, 'settings']);
    }

    public function destroy(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, RemoveService $removeService): RedirectResponse
    {
        $instance = ProjectScope::instance($project, $environment, $service);
        Gate::authorize('delete', $instance);

        $removeService->handle($instance, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id, $service->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removal of {$service->name} staged. Deploy to apply it; volumes are kept."]);

        return to_route('projects.canvas', [$project, $environment]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\CreateVariable;
use App\Actions\Variables\DeleteVariable;
use App\Actions\Variables\UpdateVariable;
use App\Data\Variables\VariableInput;
use App\Data\Variables\VariableUpdate;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Queries\ServiceVariablesView;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Service panel → Variables (docs/06 §3.4). Every edit is staged until Deploy.
 */
final class ServiceVariablesController extends Controller
{
    use ScopesProjectRoutes;

    public function index(Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ServiceVariablesView $view): JsonResponse
    {
        $instance = $this->scopeInstance($project, $environment, $service);
        Gate::authorize('view', $instance);

        return response()->json($view->get($environment, $service, $user))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, VariableInput $input, #[CurrentUser] User $user, CreateVariable $create): JsonResponse
    {
        $this->scopeInstance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $variable = $create->handle($project, $service, $input, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id, $service->public_id));

        return response()->json(['id' => $variable->public_id], 201);
    }

    public function update(Request $request, Project $project, Environment $environment, Variable $variable, VariableUpdate $update, #[CurrentUser] User $user, UpdateVariable $action): Response
    {
        $this->scopeVariable($project, $environment, $variable);
        Gate::authorize('update', $variable);

        // Turning a secret into config makes its value readable: only for
        // members who may reveal it anyway (docs/06 §3.1).
        if ($variable->type === VariableType::Secret && $update->type === VariableType::Config) {
            Gate::authorize('reveal', $variable);
        }

        $action->handle($variable, $update, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        return response()->noContent();
    }

    public function destroy(Request $request, Project $project, Environment $environment, Variable $variable, #[CurrentUser] User $user, DeleteVariable $delete): Response
    {
        $this->scopeVariable($project, $environment, $variable);
        Gate::authorize('delete', $variable);

        $delete->handle($variable, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));

        return response()->noContent();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\ImportVariables;
use App\Actions\Variables\PreviewEnvImport;
use App\Data\Variables\EnvImportData;
use App\Data\Variables\VariableTargetData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Paste a `.env` anywhere → preview with conflicts and detected secrets →
 * import into this environment (docs/06 §3.4). Staged.
 */
final class VariableImportController extends Controller
{
    public function preview(Project $project, Environment $environment, Service $service, EnvImportData $data, PreviewEnvImport $preview): JsonResponse
    {
        RouteScope::instance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        return response()->json($preview->handle($environment, $service, $data->content));
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, EnvImportData $data, #[CurrentUser] User $user, ImportVariables $import): JsonResponse
    {
        RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('create', [Variable::class, $project]);

        $result = $import->handle(
            $project,
            $service,
            $data->content,
            [VariableTargetData::environment($environment->public_id)],
            $user,
            $this->auditActor($request, $user),
            $data->only,
            $data->typesByKey(),
        );

        return response()->json($result);
    }
}

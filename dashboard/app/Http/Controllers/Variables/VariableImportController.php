<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\ImportVariables;
use App\Actions\Variables\PreviewEnvImport;
use App\Data\Variables\VariableTargetData;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Paste a `.env` anywhere → preview with conflicts and detected secrets →
 * import into this environment (docs/06 §3.4). Staged.
 */
final class VariableImportController extends Controller
{
    use ScopesProjectRoutes;

    private const int MAX_BYTES = 524288;

    public function preview(Request $request, Project $project, Environment $environment, Service $service, PreviewEnvImport $preview): JsonResponse
    {
        $this->scopeInstance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $request->validate(['content' => ['required', 'string', 'max:'.self::MAX_BYTES]]);

        return response()->json($preview->handle($environment, $service, $request->string('content')->value()));
    }

    public function store(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ImportVariables $import): JsonResponse
    {
        $this->scopeInstance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $request->validate([
            'content' => ['required', 'string', 'max:'.self::MAX_BYTES],
            'only' => ['nullable', 'array', 'max:1000'],
            'only.*' => ['string', 'max:256'],
            'types' => ['nullable', 'array', 'max:1000'],
            'types.*' => ['string', Rule::enum(VariableType::class)],
        ]);

        $only = $request->has('only') ? array_values(array_filter($request->array('only'), is_string(...))) : null;
        $types = [];

        foreach ($request->array('types') as $key => $type) {
            if (is_string($key) && is_string($type)) {
                $types[$key] = VariableType::from($type);
            }
        }

        $result = $import->handle(
            $project,
            $service,
            $request->string('content')->value(),
            [VariableTargetData::environment($environment->public_id)],
            $user,
            $this->auditActor($request, $user),
            $only,
            $types,
        );

        broadcast(new CanvasChanged($environment->public_id, $service->public_id));

        return response()->json($result);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\ApplyRawVariables;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Queries\EnvironmentVariableRows;
use App\Queries\ServiceVariablesView;
use App\Services\Variables\VariableStore;
use App\Support\Variables\EnvFileWriter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Raw `.env` mode of the variables editor (docs/06 §3.4): config values as
 * they are, secrets as a placeholder that keeps the stored value on save.
 */
final class RawVariablesController extends Controller
{
    use ScopesProjectRoutes;

    public function show(Project $project, Environment $environment, Service $service, EnvironmentVariableRows $rows, VariableStore $store): JsonResponse
    {
        $this->scopeInstance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $lines = $rows->get($environment, $service)->map(static fn (Variable $variable): string => EnvFileWriter::line(
            $variable->key,
            $variable->type === VariableType::Secret ? ServiceVariablesView::SECRET_PLACEHOLDER : $store->plaintext($project, $variable),
        ))->all();

        return response()
            ->json(['content' => $lines === [] ? '' : implode("\n", $lines)."\n"])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Project $project, Environment $environment, Service $service, #[CurrentUser] User $user, ApplyRawVariables $apply): JsonResponse
    {
        $this->scopeInstance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $request->validate(['content' => ['present', 'nullable', 'string', 'max:524288']]);
        $content = $request->string('content')->value();

        $result = $apply->handle($environment, $service, $content, $user, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id, $service->public_id));

        return response()->json($result);
    }
}

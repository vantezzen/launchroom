<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\ApplyRawVariables;
use App\Data\Variables\RawVariablesData;
use App\Enums\VariableType;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
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
    public function show(Project $project, Environment $environment, Service $service, EnvironmentVariableRows $rows, VariableStore $store): JsonResponse
    {
        RouteScope::instance($project, $environment, $service);
        Gate::authorize('create', [Variable::class, $project]);

        $lines = $rows->get($environment, $service)->map(static fn (Variable $variable): string => EnvFileWriter::line(
            $variable->key,
            $variable->type === VariableType::Secret ? ServiceVariablesView::SECRET_PLACEHOLDER : $store->plaintext($project, $variable),
        ))->all();

        return response()
            ->json(['content' => $lines === [] ? '' : implode("\n", $lines)."\n"])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Project $project, Environment $environment, Service $service, RawVariablesData $data, #[CurrentUser] User $user, ApplyRawVariables $apply): JsonResponse
    {
        RouteScope::editable(RouteScope::instance($project, $environment, $service));
        Gate::authorize('create', [Variable::class, $project]);

        $result = $apply->handle($environment, $service, $data->content ?? '', $user, $this->auditActor($request, $user));

        return response()->json($result);
    }
}

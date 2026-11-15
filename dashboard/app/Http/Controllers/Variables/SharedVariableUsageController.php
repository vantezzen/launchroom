<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\SetSharedVariableUsage;
use App\Data\Variables\SharedVariableUsageData;
use App\Http\Controllers\Controller;
use App\Http\Routing\RouteScope;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Variable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * "Use in this service" for a shared variable (docs/06 §3.1). Staged.
 */
final class SharedVariableUsageController extends Controller
{
    public function __invoke(Project $project, Environment $environment, Service $service, Variable $variable, SharedVariableUsageData $data, SetSharedVariableUsage $usage): Response
    {
        RouteScope::editable(RouteScope::instance($project, $environment, $service));
        RouteScope::environment($project, $environment);
        RouteScope::variable($project, $variable);
        Gate::authorize('create', [Variable::class, $project]);

        $usage->handle($variable, $service, $data->used);

        return response()->noContent();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Variables;

use App\Actions\Variables\SetSharedVariableUsage;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * "Use in this service" for a shared variable (docs/06 §3.1). Staged.
 */
final class SharedVariableUsageController extends Controller
{
    use ScopesProjectRoutes;

    public function __invoke(Request $request, Project $project, Environment $environment, Service $service, Variable $variable, SetSharedVariableUsage $usage): Response
    {
        $this->scopeInstance($project, $environment, $service);
        $this->scopeVariable($project, $environment, $variable);
        Gate::authorize('create', [Variable::class, $project]);

        $request->validate(['used' => ['required', 'boolean']]);
        $usage->handle($variable, $service, $request->boolean('used'));
        broadcast(new CanvasChanged($environment->public_id, $service->public_id));

        return response()->noContent();
    }
}

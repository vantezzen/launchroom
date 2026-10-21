<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blueprints;

use App\Actions\Blueprints\AddTemplateToEnvironment;
use App\Data\Templates\UseTemplateData;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Projects\ProjectScope;
use App\Models\Connection;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Models\Variable;
use App\Services\Templates\TemplateCatalog;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Add from catalog" on a canvas (docs/17 §4): the catalog as JSON for
 * the add sheet, and adding a template's services to this environment
 * (staged, docs/06 §4).
 */
final class CatalogController extends Controller
{
    public function index(Project $project, Environment $environment, TemplateCatalog $catalog): JsonResponse
    {
        Gate::authorize('create', [Service::class, $project]);
        ProjectScope::environment($project, $environment);

        return new JsonResponse(['templates' => $catalog->summaries(), 'categories' => TemplateCompiler::CATEGORIES]);
    }

    public function store(Request $request, Project $project, Environment $environment, string $template, UseTemplateData $data, #[CurrentUser] User $user, TemplateCatalog $catalog, AddTemplateToEnvironment $add): RedirectResponse
    {
        Gate::authorize('create', [Service::class, $project]);
        Gate::authorize('create', [Connection::class, $project]);
        Gate::authorize('create', [Variable::class, $project]);
        ProjectScope::environment($project, $environment);
        $definition = $catalog->template($template);
        abort_if(! $definition instanceof TemplateDefinition, 404);

        $services = $add->handle($project, $environment, $definition, $data, $user, $this->auditActor($request, $user));

        broadcast(new CanvasChanged($environment->public_id))->toOthers();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$definition->name} added (".count($services).' '.(count($services) === 1 ? 'service' : 'services').'). Deploy to start it.']);

        return to_route('projects.canvas', [$project, $environment]);
    }
}

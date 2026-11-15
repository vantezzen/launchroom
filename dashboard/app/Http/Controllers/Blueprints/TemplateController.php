<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blueprints;

use App\Actions\Blueprints\CreateProjectFromTemplate;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Templates\UseTemplateData;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Templates\TemplateCatalog;
use App\Support\Templates\TemplateCompiler;
use App\Support\Templates\TemplateDefinition;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Start from a template" (`/projects/new/templates`, docs/17 §4): the
 * gallery, the detail sheet with its guided setup, and the direct first
 * deploy.
 */
final class TemplateController extends Controller
{
    public function index(TemplateCatalog $catalog, #[CurrentTeam] Team $team): Response
    {
        Gate::authorize('create', [Project::class, $team]);

        return Inertia::render('projects/templates', [
            'templates' => $catalog->summaries(),
            'categories' => TemplateCompiler::CATEGORIES,
        ]);
    }

    public function store(Request $request, string $template, UseTemplateData $data, #[CurrentUser] User $user, TemplateCatalog $catalog, CreateProjectFromTemplate $create, DeployEnvironment $deploy, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $team]);
        $definition = $catalog->template($template);
        abort_if(! $definition instanceof TemplateDefinition, 404);

        $actor = $this->auditActor($request, $user);
        $project = $create->handle($team, $definition, $data, $user, $actor);
        $production = $project->production()->sole();

        if (! $data->deploy) {
            Toast::success("{$project->name} is ready. Set its variables, then deploy.");
        } elseif (Gate::allows('deploy', $production)) {
            try {
                $deploy->handle($production, $user, $actor, "Created from the {$definition->name} template", DeploymentTrigger::Config);
                Toast::success("{$project->name} is deploying.");
            } catch (DomainRuleViolation|UnresolvedReferences $e) {
                Toast::warning("{$project->name} is ready but didn’t deploy: {$e->getMessage()}");
            }
        }

        return to_route('projects.canvas', [$project, $production]);
    }
}

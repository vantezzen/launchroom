<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blueprints;

use App\Actions\Blueprints\ImportCompose;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Blueprints\ComposeImportData;
use App\Data\Blueprints\ComposePreviewData;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\TeamSettingsController;
use App\Models\Project;
use App\Models\User;
use App\Services\Compose\ComposeSources;
use App\Support\Blueprints\BlueprintPreview;
use App\Support\Compose\ComposeRejected;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Import a Compose file" (`/projects/new/compose`, docs/17 §2.1): paste,
 * upload or read the file from a repository, preview the canvas it
 * becomes with a note per line, then create and deploy it.
 */
final class ComposeImportController extends Controller
{
    public function create(#[CurrentUser] User $user): Response
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        return Inertia::render('projects/new-compose');
    }

    /**
     * The preview: pure, nothing is stored. Errors of the file as a whole
     * are part of the answer, not a failure.
     */
    public function preview(ComposeImportData $data, #[CurrentUser] User $user, ComposeSources $sources): JsonResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        try {
            ['translation' => $translation, 'path' => $path] = $sources->translate($team, $data);
        } catch (ComposeRejected $e) {
            return new JsonResponse(new ComposePreviewData(preview: null, error: $e->getMessage(), errorLine: $e->yamlLine));
        } catch (DomainRuleViolation $e) {
            return new JsonResponse(new ComposePreviewData(preview: null, error: $e->getMessage()));
        }

        return new JsonResponse(new ComposePreviewData(
            preview: BlueprintPreview::of($translation->blueprint, $translation->choices),
            notes: $translation->notes,
            inputs: $translation->inputs,
            missingFiles: $translation->missingFiles,
            skipped: $translation->skipped,
            suggestedName: $translation->blueprint->name,
            path: $path,
        ));
    }

    public function store(Request $request, ComposeImportData $data, #[CurrentUser] User $user, ImportCompose $import, DeployEnvironment $deploy): RedirectResponse
    {
        $team = TeamSettingsController::currentTeam($user);
        Gate::authorize('create', [Project::class, $team]);

        $actor = $this->auditActor($request, $user);
        $project = $import->handle($team, $data, $user, $actor);
        $production = $project->production()->sole();

        if (Gate::allows('deploy', $production)) {
            try {
                $deploy->handle($production, $user, $actor, 'Imported from a Compose file', DeploymentTrigger::Config);
                Inertia::flash('toast', ['type' => 'success', 'message' => "{$project->name} is deploying. Watch it go live here."]);
            } catch (DomainRuleViolation|UnresolvedReferences $e) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => "{$project->name} is ready but didn’t deploy: {$e->getMessage()}"]);
            }
        }

        return to_route('projects.canvas', [$project, $production]);
    }
}

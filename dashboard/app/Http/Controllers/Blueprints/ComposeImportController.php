<?php

declare(strict_types=1);

namespace App\Http\Controllers\Blueprints;

use App\Actions\Blueprints\ImportCompose;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Blueprints\BlueprintServicePreviewData;
use App\Data\Blueprints\ComposeImportData;
use App\Data\Blueprints\ComposePreviewData;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Http\Controllers\Controller;
use App\Http\Routing\CurrentTeam;
use App\Http\Toast;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Compose\ComposeSources;
use App\Support\Blueprints\BlueprintPreview;
use App\Support\Compose\ComposeRejected;
use App\Support\Compose\ComposeTranslator;
use App\Support\Compose\ComposeYaml;
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
    public function create(#[CurrentTeam] Team $team): Response
    {
        Gate::authorize('create', [Project::class, $team]);

        return Inertia::render('projects/new-compose', ['maxBytes' => ComposeYaml::MAX_BYTES]);
    }

    /**
     * The preview: pure, nothing is stored. Errors of the file as a whole
     * are part of the answer, not a failure.
     */
    public function preview(ComposeImportData $data, ComposeSources $sources, #[CurrentTeam] Team $team): JsonResponse
    {
        Gate::authorize('create', [Project::class, $team]);

        try {
            ['translation' => $translation, 'repository' => $repository, 'path' => $path] = $sources->translate($team, $data);
        } catch (ComposeRejected $e) {
            return new JsonResponse(new ComposePreviewData(preview: null, error: $e->getMessage(), errorLine: $e->yamlLine));
        } catch (DomainRuleViolation $e) {
            return new JsonResponse(new ComposePreviewData(preview: null, error: $e->getMessage()));
        }

        $preview = BlueprintPreview::of($translation->blueprint, $translation->choices);
        $first = collect($preview->services)->first(fn (BlueprintServicePreviewData $service): bool => $service->public) ?? $preview->services[0] ?? null;

        return new JsonResponse(new ComposePreviewData(
            preview: $preview,
            notes: $translation->notes,
            inputs: $translation->inputs,
            missingFiles: $translation->missingFiles,
            skipped: $translation->skipped,
            suggestedName: ComposeTranslator::suggestedName($translation->blueprint, $repository?->repositoryFullName()) ?? $first?->name,
            path: $path,
        ));
    }

    public function store(Request $request, ComposeImportData $data, #[CurrentUser] User $user, ImportCompose $import, DeployEnvironment $deploy, #[CurrentTeam] Team $team): RedirectResponse
    {
        Gate::authorize('create', [Project::class, $team]);

        $actor = $this->auditActor($request, $user);
        $project = $import->handle($team, $data, $user, $actor);
        $production = $project->production()->sole();

        if (! $data->deploy) {
            Toast::success("{$project->name} is ready. Set its variables, then deploy.");
        } elseif (Gate::allows('deploy', $production)) {
            try {
                $deploy->handle($production, $user, $actor, 'Imported from a Compose file', DeploymentTrigger::Config);
                Toast::success("{$project->name} is deploying.");
            } catch (DomainRuleViolation|UnresolvedReferences $e) {
                Toast::warning("{$project->name} is ready but didn’t deploy: {$e->getMessage()}");
            }
        }

        return to_route('projects.canvas', [$project, $production]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dockerfiles;

use App\Actions\Dockerfiles\UpdateAiProviderSettings;
use App\Actions\Dockerfiles\UpdateTeamDockerfileGeneration;
use App\Data\Dockerfiles\AiProviderSettingsData;
use App\Data\Dockerfiles\TeamDockerfileGenerationData;
use App\Data\Dockerfiles\UpdateAiProviderData;
use App\Http\Controllers\Controller;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\User;
use App\Support\Dockerfiles\RepositoryContext;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Dockerfile generation (docs/20 §1): the instance's provider
 * (instance owners, fresh password) and the current team's switch (team
 * owners). Everyone in the team can read what is shared and why.
 */
final class DockerfileGenerationSettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        $team = $user->currentTeam()->firstOrFail();
        Gate::authorize('view', $team);

        $suggested = config('launchroom.dockerfile_generation.suggested_models');

        return Inertia::render('settings/dockerfile-generation', [
            'provider' => AiProviderSettingsData::fromModel(
                AiProviderSettings::current(),
                Gate::allows('manageInstance'),
                is_array($suggested) ? array_values(array_filter($suggested, is_string(...))) : [],
                config('launchroom.dockerfile_generation.model') === 'stub',
            ),
            'team' => [
                'name' => $team->name,
                'enabled' => $team->settings->generateDockerfiles,
                'canChange' => Gate::allows('update', $team),
            ],
            'limits' => [
                'files' => RepositoryContext::MAX_FILES,
                'fileKb' => intdiv(RepositoryContext::MAX_FILE_BYTES, 1024),
                'totalKb' => intdiv(RepositoryContext::MAX_TOTAL_BYTES, 1024),
                'listing' => RepositoryContext::MAX_TREE,
                'repairs' => config()->integer('launchroom.dockerfile_generation.repairs'),
            ],
        ]);
    }

    public function update(Request $request, UpdateAiProviderData $data, #[CurrentUser] User $user, UpdateAiProviderSettings $update): RedirectResponse
    {
        Gate::authorize('manageInstance');

        $update->handle($data, $user, $this->auditActor($request, $user));

        return back()->with('success', 'Dockerfile generation settings saved.');
    }

    public function team(Request $request, TeamDockerfileGenerationData $data, #[CurrentUser] User $user, UpdateTeamDockerfileGeneration $update): RedirectResponse
    {
        $team = $user->currentTeam()->firstOrFail();
        Gate::authorize('update', $team);

        $update->handle($team, $data->enabled, $this->auditActor($request, $user));

        return back();
    }
}

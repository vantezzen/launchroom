<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\RenameProject;
use App\Data\Canvas\RenameProjectData;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Projects\DeleteProjectData;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\EnvironmentKind;
use App\Enums\ProjectSettingsSection;
use App\Http\Controllers\Concerns\DeletesWithFinalBackup;
use App\Http\Controllers\Controller;
use App\Http\Toast;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectSettingsPage;
use App\Queries\ProjectShell;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project settings (`/p/{project}/settings/{section}`, docs/08 §2).
 */
final class ProjectSettingsController extends Controller
{
    use DeletesWithFinalBackup;

    public function show(Project $project, ProjectSettingsPage $page, #[CurrentUser] User $user, ?ProjectSettingsSection $section = null): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/settings', [
            ...ProjectShell::props($project, null),
            ...$page->for($project, $section ?? ProjectSettingsSection::General, $user),
            // Without a section: General beside the sidebar on wide screens, the list of pages on phones.
            'index' => ! $section instanceof ProjectSettingsSection,
        ]);
    }

    public function destroy(Request $request, Project $project, DeleteProjectData $data, #[CurrentUser] User $user, DeleteProject $deleteProject): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $this->authorizeSkippingFinalBackup($data->skipFinalBackup, $project->team_id);

        $name = $project->name;
        $outcome = $deleteProject->handle($project, $data, $this->auditActor($request, $user));
        $this->flashDeletionOutcome($outcome, $name, 'project', 'See the Danger zone.');

        return $outcome === DeletionOutcome::Deleted ? to_route('projects.index') : back();
    }

    public function update(Request $request, Project $project, RenameProjectData $data, #[CurrentUser] User $user, RenameProject $renameProject): RedirectResponse
    {
        Gate::authorize('update', $project);

        $renameProject->handle($project, $data, $this->auditActor($request, $user));
        Toast::success('Project renamed.');

        return back();
    }

    public function storeEnvironment(Request $request, Project $project, CreateEnvironmentData $data, #[CurrentUser] User $user, CreateEnvironment $createEnvironment): RedirectResponse
    {
        Gate::authorize('create', [Environment::class, $project]);

        if ($data->baseEnvironmentId !== null) {
            abort_unless($project->environments()->where('public_id', $data->baseEnvironmentId)->where('kind', '!=', EnvironmentKind::Preview->value)->exists(), 404);
        }

        $environment = $createEnvironment->handle($project, $data, $user, $this->auditActor($request, $user));
        Toast::success("{$environment->name} created. Deploy it to start its services.");

        return to_route('projects.canvas', [$project, $environment]);
    }
}

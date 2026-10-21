<?php

declare(strict_types=1);

namespace App\Http\Controllers\Environments;

use App\Actions\Deployments\PromoteStagedDeployment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Actions\Environments\ResumeAutoDeploy;
use App\Actions\Environments\UpdateEnvironment;
use App\Actions\Projects\SetSecretsPolicy;
use App\Data\Environments\DeleteEnvironmentData;
use App\Data\Environments\UpdateEnvironmentData;
use App\Enums\Backups\DeletionOutcome;
use App\Events\CanvasChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Deployments\ScopesProjectRoutes;
use App\Models\Backups\DeletionRequest;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Managing environments (docs/06 §1–2, §6.5; docs/08 §2 settings/environments):
 * rename and triggers, delete, resume auto-deploy after a rollback, revert to
 * an older revision, promote a staged deployment, the secrets policy.
 */
final class EnvironmentController extends Controller
{
    use ScopesProjectRoutes;

    public function update(Request $request, Project $project, Environment $environment, UpdateEnvironmentData $data, #[CurrentUser] User $user, UpdateEnvironment $update): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('update', $environment);

        $update->handle($environment, $data, $this->auditActor($request, $user));
        broadcast(new CanvasChanged($environment->public_id));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Environment saved.']);

        return back();
    }

    public function destroy(Request $request, Project $project, Environment $environment, DeleteEnvironmentData $data, #[CurrentUser] User $user, DeleteEnvironment $delete): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('delete', $environment);

        if ($data->skipFinalBackup) {
            Gate::authorize('skipFinalBackup', [DeletionRequest::class, $environment->team_id]);
        }

        $name = $environment->name;
        $outcome = $delete->handle($environment, $data, $this->auditActor($request, $user));
        Inertia::flash('toast', match ($outcome) {
            DeletionOutcome::Deleted => ['type' => 'success', 'message' => "{$name} deleted. Its services are being stopped."],
            DeletionOutcome::Blocked => ['type' => 'error', 'message' => "{$name} was not deleted: its final backup failed."],
            DeletionOutcome::WaitingForFinalBackup => ['type' => 'info', 'message' => "Taking a final backup of {$name}'s data first. The environment is deleted as soon as it succeeded."],
        });

        return to_route('projects.settings', ['project' => $project->public_id, 'section' => 'environments']);
    }

    public function resume(Request $request, Project $project, Environment $environment, #[CurrentUser] User $user, ResumeAutoDeploy $resume): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        Gate::authorize('deploy', $environment);

        $resume->handle($environment, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Auto-deploy resumed: new pushes go live in {$environment->name} again."]);

        return back();
    }

    /**
     * "Revert to revision N" (docs/06 §6.5): that revision's intent becomes
     * the staged changes; nothing is deployed yet.
     */
    public function revert(Request $request, Project $project, Environment $environment, EnvironmentRevision $revision, #[CurrentUser] User $user, DiscardChanges $discard): RedirectResponse
    {
        $this->scopeEnvironment($project, $environment);
        abort_unless($revision->environment_id === $environment->id, 404);
        Gate::authorize('deploy', $environment);

        $count = $discard->handle($environment, null, $this->auditActor($request, $user), $revision);
        broadcast(new CanvasChanged($environment->public_id));
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 0
            ? "Nothing to revert: {$environment->name} already matches revision {$revision->number}."
            : "Revision {$revision->number} restored as {$count} staged ".($count === 1 ? 'change' : 'changes').'. Review and deploy them.']);

        return to_route('staged-changes.show', [$project->public_id, $environment->public_id]);
    }

    public function promoteDeployment(Request $request, Project $project, Environment $environment, Deployment $deployment, #[CurrentUser] User $user, PromoteStagedDeployment $promote): RedirectResponse
    {
        $this->scopeDeployment($project, $environment, $deployment);
        Gate::authorize('promote', $deployment);

        $promote->handle($deployment, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promoted: traffic is moving to the staged version.']);

        return to_route('deployments.show', [$project->public_id, $environment->public_id, $deployment->public_id]);
    }

    public function secretsPolicy(Request $request, Project $project, #[CurrentUser] User $user, SetSecretsPolicy $setPolicy): RedirectResponse
    {
        Gate::authorize('update', $project);

        $request->validate(['productionSecretsMustDiffer' => ['required', 'boolean']]);
        $setPolicy->handle($project, $request->boolean('productionSecretsMustDiffer'), $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Secrets policy saved.']);

        return back();
    }
}

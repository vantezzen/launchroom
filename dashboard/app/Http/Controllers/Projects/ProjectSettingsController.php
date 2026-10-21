<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Environments\CreateEnvironment;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\RenameProject;
use App\Data\Backups\DeletionRequestData;
use App\Data\Canvas\EnvironmentData;
use App\Data\Canvas\RenameProjectData;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Environments\EnvironmentSettingsData;
use App\Data\Logs\RuntimeLogRetentionData;
use App\Data\Projects\DeleteProjectData;
use App\Data\Projects\ProjectIconData;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\EnvironmentKind;
use App\Http\Controllers\Controller;
use App\Models\Backups\DeletionRequest;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\ConfigAsCode\ConfigFilePage;
use App\Queries\Previews\PreviewSettingsPage;
use App\Queries\ProjectBackupFootprint;
use App\Queries\ProjectLogRetention;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Database\Eloquent\Builder;
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
    public const array SECTIONS = ['general', 'environments', 'previews', 'logs', 'danger'];

    public function show(Project $project, ProjectBackupFootprint $backups, #[CurrentUser] User $user, string $section = 'general'): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/settings', [
            ...ProjectScope::shellProps($project, null),
            'section' => $section,
            'icon' => ProjectIconData::fromProject($project),
            'allEnvironments' => $project->environments()
                ->orderByRaw("case kind when 'production' then 0 when 'persistent' then 1 else 2 end")
                ->orderBy('name')
                ->get()
                ->map(static fn (Environment $environment): EnvironmentData => EnvironmentData::fromEnvironment($environment))
                ->all(),
            'environmentSettings' => $section === 'environments' ? $this->environmentSettings($project, $backups, $user) : [],
            // Settings → Logs: runtime log retention per environment (docs/08 §12).
            'logRetention' => $section === 'logs' ? resolve(ProjectLogRetention::class)->for($project) : [],
            'logRetentionOptions' => ['days' => RuntimeLogRetentionData::dayOptions(), 'maxMb' => RuntimeLogRetentionData::sizeOptions()],
            // Settings → General → Config file (docs/18 §3.1).
            'configFile' => $section === 'general' ? resolve(ConfigFilePage::class)->for($project) : null,
            'secretsPolicy' => [
                'project' => $project->settings->productionSecretsMustDiffer,
                'team' => $project->team->settings->productionSecretsMustDiffer,
            ],
            // Settings → Previews (docs/06 §5), App\Http\Controllers\Previews\PreviewSettingsController.
            'previewSettings' => $section === 'previews' ? resolve(PreviewSettingsPage::class)->for($project, $user) : null,
            'canCreateEnvironment' => Gate::allows('create', [Environment::class, $project]),
            'canDelete' => Gate::allows('delete', $project),
            'deletion' => $section === 'danger' && Gate::allows('delete', $project) ? [
                ...$backups->for($project),
                'services' => $project->services()->count(),
                'environments' => $project->environments()->count(),
                // A deletion waiting for its final backup (docs/14 §9.13).
                'pending' => DeletionRequestData::openFor(DeletionRequest::PROJECT, $project->id, $user),
                'canSkipFinalBackup' => Gate::allows('skipFinalBackup', [DeletionRequest::class, $project->team_id]),
            ] : null,
        ]);
    }

    /**
     * @return list<EnvironmentSettingsData>
     */
    private function environmentSettings(Project $project, ProjectBackupFootprint $backups, User $user): array
    {
        $environments = $project->environments()
            ->with(['baseEnvironment:id,name', 'lastAppliedRevision:id,number,created_at'])
            ->withCount(['serviceInstances' => static fn (Builder $query) => $query->whereNull('removed_at')])
            ->orderByRaw("case kind when 'production' then 0 when 'persistent' then 1 else 2 end")
            ->orderBy('name')
            ->get();

        $canSkip = Gate::allows('skipFinalBackup', [DeletionRequest::class, $project->team_id]);

        return array_values($environments->map(static function (Environment $environment) use ($backups, $user, $canSkip): EnvironmentSettingsData {
            $footprint = $environment->kind === EnvironmentKind::Production ? ['servicesWithBackups' => 0, 'inFlight' => false] : $backups->forEnvironment($environment);
            $count = $environment->getAttribute('service_instances_count');

            return new EnvironmentSettingsData(
                id: $environment->public_id,
                name: $environment->name,
                slug: $environment->slug,
                kind: $environment->kind,
                triggers: $environment->triggers,
                autoDeployPaused: $environment->auto_deploy_paused,
                baseName: $environment->baseEnvironment?->name,
                services: is_numeric($count) ? (int) $count : 0,
                revision: $environment->lastAppliedRevision?->number,
                lastDeployedAt: $environment->lastAppliedRevision?->created_at->toIso8601String(),
                canUpdate: Gate::allows('update', $environment),
                canDelete: Gate::allows('delete', $environment),
                servicesWithBackups: $footprint['servicesWithBackups'],
                backupInFlight: $footprint['inFlight'],
                pendingDeletion: DeletionRequestData::openFor(DeletionRequest::ENVIRONMENT, $environment->id, $user),
                canSkipFinalBackup: $canSkip,
            );
        })->all());
    }

    /**
     * Deletes the project after the typed confirmation (docs/08 §2 "danger").
     */
    public function destroy(Request $request, Project $project, DeleteProjectData $data, #[CurrentUser] User $user, DeleteProject $deleteProject): RedirectResponse
    {
        Gate::authorize('delete', $project);

        if ($data->skipFinalBackup) {
            Gate::authorize('skipFinalBackup', [DeletionRequest::class, $project->team_id]);
        }

        $name = $project->name;
        $outcome = $deleteProject->handle($project, $data, $this->auditActor($request, $user));

        if ($outcome !== DeletionOutcome::Deleted) {
            Inertia::flash('toast', $outcome === DeletionOutcome::Blocked
                ? ['type' => 'error', 'message' => "{$name} was not deleted: its final backup failed. See the Danger zone."]
                : ['type' => 'info', 'message' => "Taking a final backup of {$name}'s data first. The project is deleted as soon as it succeeded."]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$name} deleted. Its services are being stopped."]);

        return to_route('projects.index');
    }

    public function update(Request $request, Project $project, RenameProjectData $data, #[CurrentUser] User $user, RenameProject $renameProject): RedirectResponse
    {
        Gate::authorize('update', $project);

        $renameProject->handle($project, $data, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Project renamed.']);

        return back();
    }

    public function storeEnvironment(Request $request, Project $project, CreateEnvironmentData $data, #[CurrentUser] User $user, CreateEnvironment $createEnvironment): RedirectResponse
    {
        Gate::authorize('create', [Environment::class, $project]);

        if ($data->baseEnvironmentId !== null) {
            abort_unless($project->environments()->where('public_id', $data->baseEnvironmentId)->where('kind', '!=', EnvironmentKind::Preview->value)->exists(), 404);
        }

        $environment = $createEnvironment->handle($project, $data, $user, $this->auditActor($request, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$environment->name} created. Deploy it to start its services."]);

        return to_route('projects.canvas', [$project, $environment]);
    }
}

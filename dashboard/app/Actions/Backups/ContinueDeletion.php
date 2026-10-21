<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Projects\DeleteProject;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\EnvironmentKind;
use App\Models\Backups\DeletionRequest;
use App\Models\Environment;
use App\Models\Project;

/**
 * Runs an environment or project deletion whose final backups succeeded or
 * were overridden (docs/14 §9.13). Preview environments are left to the
 * preview tick (their pending close retries DestroyPreview, which also
 * updates the preview); staged service removals continue with the next
 * Deploy. A subject that is gone otherwise cancels the request.
 */
final readonly class ContinueDeletion
{
    public function __construct(
        private DeleteEnvironment $deleteEnvironment,
        private DeleteProject $deleteProject,
    ) {}

    public function handle(DeletionRequest $request, AuditActor $actor): ?DeletionOutcome
    {
        if (! $request->status->allowsDeletion()) {
            return null;
        }

        if ($request->subject_type === DeletionRequest::ENVIRONMENT) {
            $environment = Environment::query()->whereKey($request->subject_id)->where('team_id', $request->team_id)->first();

            if (! $environment instanceof Environment) {
                $request->transitionTo(DeletionRequestStatus::Cancelled);

                return null;
            }

            return $environment->kind === EnvironmentKind::Preview ? null : $this->deleteEnvironment->handle($environment, null, $actor);
        }

        if ($request->subject_type === DeletionRequest::PROJECT) {
            $project = Project::query()->whereKey($request->subject_id)->where('team_id', $request->team_id)->first();

            if (! $project instanceof Project) {
                $request->transitionTo(DeletionRequestStatus::Cancelled);

                return null;
            }

            return $this->deleteProject->handle($project, null, $actor);
        }

        return null;
    }
}

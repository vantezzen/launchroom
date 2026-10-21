<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Deployments\DeploymentFactory;
use Illuminate\Support\Facades\DB;

/**
 * Instant rollback (docs/04 §8): a new deployment that re-uses a previous
 * deployment's retained image and env snapshot (no rebuild), applied
 * immediately (no staging). Rolling back production pauses auto-deploy for
 * the environment. Database migrations are not reverted.
 */
final readonly class RollbackDeployment
{
    public function __construct(
        private ProjectKeyring $keyring,
        private DeploymentFactory $factory,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Deployment $target, ?User $user, AuditActor $actor): Deployment
    {
        $deployment = DB::transaction(function () use ($target, $user, $actor): Deployment {
            $target = Deployment::query()->whereKey($target->id)->with(['environment.project'])->lockForUpdate()->firstOrFail();

            if ($target->status !== DeploymentStatus::Superseded || $target->image === null || $target->image_pruned_at !== null || $target->env_snapshot === null) {
                throw DomainRuleViolation::on('deployment', 'Only a previous deployment whose image is still retained can be rolled back to.');
            }

            $project = $target->environment->project;
            $env = $this->keyring->decryptSnapshot($project, $target->public_id, $target->env_snapshot);
            unset($env['LAUNCHROOM_DEPLOYMENT_ID'], $env['LAUNCHROOM_GIT_COMMIT_SHA']);

            $deployment = Deployment::query()->create([
                'team_id' => $target->team_id,
                'service_instance_id' => $target->service_instance_id,
                'environment_id' => $target->environment_id,
                'revision_id' => $target->revision_id,
                'trigger' => DeploymentTrigger::Rollback,
                'git_ref' => $target->git_ref,
                'commit_sha' => $target->commit_sha,
                'commit_message' => $target->commit_message,
                'commit_author' => $target->commit_author,
                'commit_author_avatar_url' => $target->commit_author_avatar_url,
                'build_key' => $target->build_key,
                'image' => $target->image,
                'queued_at' => now(),
                'rollback_of_id' => $target->id,
                'actor_id' => $user?->id,
            ]);

            // The build summary travels with the image (port, health path, processes).
            $deployment->forceFill(['plan' => $target->plan, 'build_server_id' => $target->build_server_id])->save();
            $this->factory->storeSnapshot($project, $deployment, $env);

            $environment = Environment::query()->whereKey($target->environment_id)->lockForUpdate()->firstOrFail();

            if ($environment->kind === EnvironmentKind::Production) {
                // "Production is rolled back. New pushes won't go live." (docs/04 §8)
                $environment->forceFill(['auto_deploy_paused' => true])->save();
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentRolledBack,
                actor: $actor,
                teamId: $target->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: ['to' => $target->public_id],
            ));

            return $deployment;
        });

        dispatch(new AdvanceDeploymentJob($deployment->id));

        return $deployment;
    }
}

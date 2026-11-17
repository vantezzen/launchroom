<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use Illuminate\Support\Facades\DB;

/**
 * Instant rollback (docs/04 §8): a previous deployment's retained image
 * and env snapshot run again ({@see DeploymentFactory::rerun()}), applied
 * immediately (no staging). Rolling back production pauses auto-deploy for
 * the environment. Database migrations are not reverted.
 *
 * `$reason`: launchroom rolls back by itself (CheckProbation, OD1); kept as
 * the new deployment's `rollback_reason` and in the audit entry.
 */
final readonly class RollbackDeployment
{
    public function __construct(
        private DeploymentFactory $factory,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Deployment $target, ?User $user, AuditActor $actor, ?string $reason = null): Deployment
    {
        return DB::transaction(function () use ($target, $user, $actor, $reason): Deployment {
            $target = Deployment::query()->whereKey($target->id)->with(['environment.project'])->lockForUpdate()->firstOrFail();

            if (! $target->isRollbackTarget()) {
                throw DomainRuleViolation::on('deployment', 'Only a previous deployment whose image is still retained can be rolled back to.');
            }

            $deployment = $this->factory->rerun($target, DeploymentTrigger::Rollback, $user, rollback: true);

            if ($reason !== null) {
                $deployment->forceFill(['rollback_reason' => mb_substr($reason, 0, 1000)])->save();
            }

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
                meta: ['to' => $target->public_id, ...($reason === null ? [] : ['automatic' => true, 'reason' => mb_substr($reason, 0, 1000)])],
            ));

            return $deployment;
        });
    }
}

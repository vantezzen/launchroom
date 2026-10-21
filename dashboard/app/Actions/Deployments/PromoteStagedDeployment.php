<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use Illuminate\Support\Facades\DB;

/**
 * "Promote" of a staged deployment (docs/06 §6.5): a deployment that was
 * built and started with `hold_traffic` and is healthy (`ready`) gets the
 * traffic. No rebuild, no restart: routing continues as for any rollout
 * (the previous version drains).
 */
final readonly class PromoteStagedDeployment
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Deployment $deployment, AuditActor $actor): Deployment
    {
        $deployment = DB::transaction(function () use ($deployment, $actor): Deployment {
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();

            if ($deployment->status !== DeploymentStatus::Ready) {
                throw DomainRuleViolation::on('deployment', 'Only a staged deployment that is ready can be promoted.');
            }

            DeploymentStep::begin($deployment, DeploymentStepName::Route);
            $deployment->transitionTo(DeploymentStatus::Routing);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentPromoted,
                actor: $actor,
                teamId: $deployment->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: [],
            ));

            return $deployment;
        });

        dispatch(new AdvanceDeploymentJob($deployment->id));

        return $deployment;
    }
}

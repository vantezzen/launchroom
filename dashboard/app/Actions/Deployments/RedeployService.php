<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Data\Audit\AuditActor;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Refusal;

/**
 * Redeploy one service (docs/08 §3.4 "[Redeploy]", {@see AttemptRedeploy}).
 */
final readonly class RedeployService
{
    public function __construct(
        private AttemptRedeploy $attempt,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(ServiceInstance $instance, ?Deployment $from, ?User $user, AuditActor $actor, DeploymentTrigger $trigger = DeploymentTrigger::Manual): Deployment
    {
        return Refusal::orThrow($this->attempt->handle($instance, $from, $user, $actor, $trigger));
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Deployments\CommitNotDeployed;
use App\Data\Git\CommitData;
use App\Data\Services\GitSource;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Git\GitHeads;
use App\Support\Git\GitRef;
use Illuminate\Support\Facades\DB;

/**
 * Deploys a commit of a git service from the API or CLI (docs/16 §2.2):
 * `sha`, else the head of branch `ref`, else the head of the branch the
 * instance tracks. Like a push ({@see DeployCommit}): the environment's
 * last applied configuration, older queued deployments superseded. Audited
 * as `deployment.requested`.
 */
final readonly class DeployRef
{
    public function __construct(
        private GitHeads $heads,
        private DeployCommit $deployCommit,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, ?string $ref, ?string $sha, User $user, AuditActor $actor, DeploymentTrigger $trigger = DeploymentTrigger::Api): Deployment
    {
        $instance->loadMissing(['service', 'environment']);
        $source = $instance->service->source;

        if (! $source instanceof GitSource) {
            throw DomainRuleViolation::on('service', 'Only services built from a git repository deploy a commit. Redeploy this one instead.');
        }

        $branch = $ref === null ? $this->heads->branchFor($instance, $source) : GitRef::shortName(str_starts_with($ref, 'refs/') ? $ref : 'refs/heads/'.$ref);

        if (! GitRef::isValid('refs/heads/'.$branch)) {
            throw DomainRuleViolation::on('ref', 'That is not a valid branch name.');
        }

        $sha ??= $this->heads->head($instance->team_id, $source, $branch);

        return DB::transaction(function () use ($instance, $user, $actor, $branch, $sha, $trigger): Deployment {
            $deployment = $this->deployCommit->handle($instance, new CommitData($sha, '', null, null, null), 'refs/heads/'.$branch, $trigger, $user);

            if ($deployment instanceof CommitNotDeployed) {
                throw DomainRuleViolation::conflict('Nothing was deployed. '.$deployment->message);
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentRequested,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: ['service' => $instance->service->public_id, 'branch' => $branch, 'sha' => $sha],
            ));
            CanvasChanged::forInstance($instance);

            return $deployment;
        });
    }
}

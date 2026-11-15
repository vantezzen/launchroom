<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Deployments\DeploymentCommit;
use App\Enums\AuditAction;
use App\Enums\DeploymentTrigger;
use App\Events\CanvasChanged;
use App\Exceptions\UnresolvedReferences;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use App\Support\Refusal;
use Illuminate\Support\Facades\DB;

/**
 * Redeploy one service (docs/08 §3.4 "[Redeploy]"), or say why it can't: a
 * new deployment of the environment's last applied revision, so staged
 * changes stay staged. The commit is the one of $from (a deployment of this
 * service instance) or of the active deployment. A matching build key lets
 * the build reuse the image (docs/04 §7). Workflows (server moves) branch on
 * the refusal; {@see RedeployService} throws it.
 */
final readonly class AttemptRedeploy
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, ?Deployment $from, ?User $user, AuditActor $actor, DeploymentTrigger $trigger = DeploymentTrigger::Manual): Deployment|Refusal
    {
        if ($from instanceof Deployment && $from->service_instance_id !== $instance->id) {
            return new Refusal('That deployment belongs to another service.', 'general', 403);
        }

        return DB::transaction(function () use ($instance, $from, $user, $actor, $trigger): Deployment|Refusal {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
            $environment = Environment::query()->whereKey($instance->environment_id)->with(['project', 'lastAppliedRevision'])->firstOrFail();
            $revision = $environment->lastAppliedRevision;

            if ($revision === null) {
                return Refusal::on('service', 'This environment has not been deployed yet. Deploy its staged changes first.');
            }

            $spec = $revision->spec;
            $service = $spec->service($instance->service->public_id);

            if ($service === null || ! $service->enabled) {
                return Refusal::on('service', 'This service is not part of the last deployed configuration. Deploy the staged changes instead.');
            }

            try {
                $env = $this->resolver->resolve($environment->project, $spec, $service);
            } catch (UnresolvedReferences $e) {
                return $e->toRefusal();
            }

            $source = $from ?? $instance->activeDeployment;

            $deployment = $this->factory->create($environment->project, $instance, $revision, $spec, $service, $env, $trigger, $user, DeploymentCommit::of($source));

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentRedeployed,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: ['service' => $instance->service->public_id, 'from' => $source?->public_id, 'revision' => $revision->number],
            ));
            CanvasChanged::forInstance($instance);

            return $deployment;
        });
    }
}

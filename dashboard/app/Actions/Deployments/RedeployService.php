<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Exceptions\DomainRuleViolation;
use App\Exceptions\UnresolvedReferences;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Deployments\DeploymentFactory;
use App\Services\Variables\SpecVariableResolver;
use Illuminate\Support\Facades\DB;

/**
 * Redeploy one service (docs/08 §3.4 "[Redeploy]"): a new deployment of the
 * environment's last applied revision, so staged changes stay staged. The
 * commit is the one of $from (a deployment of this service instance) or of
 * the active deployment. A matching build key lets the build reuse the image
 * (docs/04 §7). Older queued deployments of the instance are superseded.
 */
final readonly class RedeployService
{
    public function __construct(
        private SpecVariableResolver $resolver,
        private DeploymentFactory $factory,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, ?Deployment $from, ?User $user, AuditActor $actor, DeploymentTrigger $trigger = DeploymentTrigger::Manual): Deployment
    {
        if ($from instanceof Deployment && $from->service_instance_id !== $instance->id) {
            throw DomainRuleViolation::forbidden('That deployment belongs to another service.');
        }

        $deployment = DB::transaction(function () use ($instance, $from, $user, $actor, $trigger): Deployment {
            $instance = ServiceInstance::query()->whereKey($instance->id)->with(['service', 'activeDeployment'])->lockForUpdate()->firstOrFail();
            $environment = Environment::query()->whereKey($instance->environment_id)->with(['project', 'lastAppliedRevision'])->firstOrFail();
            $revision = $environment->lastAppliedRevision;

            if ($revision === null) {
                throw DomainRuleViolation::on('service', 'This environment has not been deployed yet. Deploy its staged changes first.');
            }

            $spec = $revision->spec;
            $service = $spec->service($instance->service->public_id);

            if ($service === null || ! $service->enabled) {
                throw DomainRuleViolation::on('service', 'This service is not part of the last deployed configuration. Deploy the staged changes instead.');
            }

            try {
                $env = $this->resolver->resolve($environment->project, $spec, $service);
            } catch (UnresolvedReferences $e) {
                throw $e->toDomainRuleViolation();
            }

            $source = $from ?? $instance->activeDeployment;

            $deployment = $this->factory->create(
                $environment->project,
                $instance,
                $revision,
                $spec,
                $service,
                $env,
                $trigger,
                $user,
                $source?->commit_sha,
                $source?->git_ref,
            );

            if ($source instanceof Deployment) {
                $deployment->forceFill([
                    'commit_message' => $source->commit_message,
                    'commit_author' => $source->commit_author,
                    'commit_author_avatar_url' => $source->commit_author_avatar_url,
                ])->save();
            }

            Deployment::query()
                ->where('service_instance_id', $instance->id)
                ->where('status', DeploymentStatus::Queued->value)
                ->whereKeyNot($deployment->id)
                ->lockForUpdate()
                ->get()
                ->each(static function (Deployment $older) use ($deployment): void {
                    $older->forceFill(['superseded_by_id' => $deployment->id]);
                    $older->transitionTo(DeploymentStatus::Superseded);
                });

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::DeploymentRedeployed,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'deployment',
                subjectId: $deployment->public_id,
                meta: ['service' => $instance->service->public_id, 'from' => $source?->public_id, 'revision' => $revision->number],
            ));

            return $deployment;
        });

        dispatch(new AdvanceDeploymentJob($deployment->id))->afterCommit();

        return $deployment;
    }
}

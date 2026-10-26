<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Data\Audit\AuditActor;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Enums\DeploymentTrigger;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Services\StagedForService;

/**
 * The service panel's Deploy (D68): a new version of the running
 * configuration. A service built from a repository builds and deploys the
 * latest commit of the branch it tracks ({@see DeployRef}); an image
 * service resolves its tag again and runs what it points to now
 * ({@see RedeployService}: a tag isn't pinned in the configuration).
 *
 * With staged changes for the service, Deploy is the environment's "Deploy
 * changes" instead (they go out with the new version), so this refuses and
 * the panel shows that flow. Stopped services start first; databases,
 * workers and crons have no version of their own to deploy (Restart).
 */
final readonly class DeployLatest
{
    public function __construct(
        private DeployRef $deployRef,
        private RedeployService $redeploy,
        private StagedForService $staged,
    ) {}

    public function handle(ServiceInstance $instance, User $user, AuditActor $actor): Deployment
    {
        $instance->loadMissing('service');
        $service = $instance->service;

        if ($instance->stopped_at !== null) {
            throw DomainRuleViolation::on('service', 'This service is stopped. Start it first.');
        }

        if (! in_array($service->kind, [ServiceKind::App, ServiceKind::Image], true)) {
            throw DomainRuleViolation::on('service', 'Only apps and image services deploy a new version. Restart this one instead.');
        }

        if ($this->staged->has($instance)) {
            throw DomainRuleViolation::conflict('This service has staged changes. Deploy them with the environment’s Deploy changes, so they go out with the new version.');
        }

        return match (true) {
            $service->source instanceof GitSource => $this->deployRef->handle($instance, null, null, $user, $actor, DeploymentTrigger::Manual),
            $service->source instanceof ImageSource => $this->redeploy->handle($instance, null, $user, $actor),
            default => throw DomainRuleViolation::on('service', 'This service has no source to deploy.'),
        };
    }
}

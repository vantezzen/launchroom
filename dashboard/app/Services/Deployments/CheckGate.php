<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Contracts\Git\ReadsCommitChecks;
use App\Data\Deployments\DeploymentChecksData;
use App\Data\Deployments\DeploymentCommit;
use App\Data\Services\GitSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentTrigger;
use App\Models\ServiceInstance;
use App\Services\Git\GitProviders;

/**
 * Whether a new deployment waits for its commit's CI checks (D111, docs/06
 * §2.5): only deployments a push or tag created, with a commit, of a
 * git-sourced service, in an environment that deploys after checks pass.
 * Everything someone started by hand (deploy, redeploy, rollback, the API,
 * promotions) and previews never wait.
 */
final readonly class CheckGate
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function forNew(ServiceInstance $instance, ServiceSpec $service, DeploymentTrigger $trigger, ?DeploymentCommit $commit): ?DeploymentChecksData
    {
        $source = $service->source();

        if (! in_array($trigger, [DeploymentTrigger::Push, DeploymentTrigger::Tag], true) || $commit?->sha === null || ! $source instanceof GitSource) {
            return null;
        }

        $triggers = $instance->loadMissing('environment')->environment->triggers;

        if (! $triggers->waitForChecks) {
            return null;
        }

        return $this->providers->forSource($source) instanceof ReadsCommitChecks
            ? DeploymentChecksData::waiting(now(), $triggers->checksTimeoutMinutes)
            : DeploymentChecksData::unsupported(now());
    }
}

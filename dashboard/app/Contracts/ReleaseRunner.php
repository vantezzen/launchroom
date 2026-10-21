<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Deployments\StepOutcome;
use App\Models\Deployment;

/**
 * Runs a deployment's release command (`php artisan migrate --force`) once,
 * before rollout, as a `run_once` task on a runtime server in the
 * environment's network with the deployment's resolved env (docs/02 §3
 * step 4, docs/03 §7). Same contract style as BuildOrchestrator: idempotent
 * advance() with the deployment row-locked; failure stops the deployment
 * (`release_error`) and the old version keeps serving.
 *
 * Bound to App\Services\Deployments\TaskReleaseRunner (`run_once` tasks).
 */
interface ReleaseRunner
{
    /**
     * @param  list<string>  $command  argv, run inside the deployment's image
     */
    public function advance(Deployment $deployment, array $command): StepOutcome;

    /**
     * The deployment failed or was cancelled while releasing: cancel the
     * release command's task.
     */
    public function cancel(Deployment $deployment): void;

    /**
     * Runs a seed command once for the deployment's service instance, after
     * its first deploy went live (non-blocking; docs/06 previews and seeds).
     *
     * @param  list<string>  $command  argv
     */
    public function seed(Deployment $deployment, array $command): void;
}

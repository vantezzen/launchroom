<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Deployments\StepOutcome;
use App\Models\Deployment;
use App\Support\Deployments\GitHeadLookup;

/**
 * Builds the image of a deployment that needs one (docs/04 §2–5, §13).
 * Bound to App\Services\Builds\TaskBuildOrchestrator (`build` daemon tasks).
 *
 * Contract:
 * - AdvanceDeployment calls advance() with the deployment row-locked, first
 *   right after it moved to `building`, then on every later advance (task
 *   completion, heartbeat, sweep) while it is `building`. It must be
 *   idempotent: create the `build` task once (e.g. keyed by deployment id),
 *   then report progress.
 * - On success it must have set `$deployment->image` to the digest-pinned
 *   reference under `launchroom.images.apps_prefix`
 *   (`127.0.0.1:5000/apps/<service id lowercased>@sha256:…`) and saved it,
 *   then return StepOutcome::succeeded().
 * - It may reuse an existing image with the same `build_key` (docs/04 §7)
 *   and succeed immediately.
 * - Workers/crons reuse their parent app's build (docs/04 §1).
 * - It never changes the deployment status itself; AdvanceDeployment does.
 * - When the build task finishes, dispatch App\Jobs\AdvanceDeploymentJob.
 * - Slow lookups (the branch head of a deployment without a commit) belong in
 *   lookupHead(), which AdvanceDeployment calls WITHOUT any lock before
 *   advance(); advance() gets the result and uses it only if the (now
 *   locked) deployment still needs it.
 */
interface BuildOrchestrator
{
    /**
     * @param  GitHeadLookup|null  $head  lookupHead()'s result from before the lock, if any
     */
    public function advance(Deployment $deployment, ?GitHeadLookup $head = null): StepOutcome;

    /**
     * Resolves the commit the next advance() would need, without locks or
     * writes (null: nothing to resolve). Called outside any transaction.
     */
    public function lookupHead(Deployment $deployment): ?GitHeadLookup;

    /**
     * The deployment was cancelled or superseded while building: cancel the
     * build task (by solve context, never pkill).
     */
    public function cancel(Deployment $deployment): void;
}

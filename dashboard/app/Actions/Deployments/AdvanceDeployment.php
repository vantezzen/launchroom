<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Servers\RenderNodeState;
use App\Actions\Transfers\EndDatabasePreparation;
use App\Contracts\BuildOrchestrator;
use App\Contracts\ImageDigestResolver;
use App\Contracts\ReleaseRunner;
use App\Data\Deployments\StepOutcome;
use App\Data\Services\ImageSource;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\WorkloadPhase;
use App\Enums\WorkloadRole;
use App\Exceptions\DesiredStateInvalid;
use App\Exceptions\ImageResolutionFailed;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Placement;
use App\Models\Server;
use App\Scheduling\PlacementFailed;
use App\Services\Deployments\Placer;
use App\Services\Moves\Prebuilds;
use App\Services\NodeStates\MeshReadiness;
use App\Services\Transfers\DatabasePreparations;
use App\Support\Deployments\EffectiveDeploy;
use App\Support\Deployments\PrefetchedInputs;
use App\Support\Images\ImageReference;
use App\Support\PublicId;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The event-driven deployment orchestrator (docs/04 §6, docs/02 §3). Daemon
 * reports, task completions and the periodic sweep call it; it row-locks the
 * deployment, checks the current step against reality and moves forward as
 * far as it can. Idempotent: calling it again without new facts changes nothing.
 *
 *     queued → [building] → [releasing] → rolling_out → health_checking → [ready] → routing → active
 *
 * - queued: waits while another deployment of the instance is in flight;
 *   resolves the image (tags → digest) or starts the build.
 * - rolling_out: stateful services first stop the previous workload and wait
 *   until the daemon reports it stopped (no two databases on one data dir);
 *   then the scheduler places the new workloads next to the old ones and the
 *   affected NodeStates are rendered.
 * - Rolling update (docs/04 §8, stateless services with more than one
 *   replica replacing serving ones, not staged): max-surge 1 /
 *   max-unavailable 0 across the replica set. One new replica at a time is
 *   placed (possibly on another server than the old ones: the scheduler
 *   decides, anti-affinity against the new replicas already placed); once
 *   every new replica so far is healthy, it receives traffic next to the old
 *   ones (NodeStateContent routes healthy replicas of a health-checking
 *   deployment), one old replica drains, and the next new one is placed.
 *   When the whole new set is healthy, routing switches as usual and the
 *   remaining old replicas drain. A failure removes the new replicas and puts
 *   the drained old ones back to serving. Rollout step meta: `rolling`,
 *   `drained` (workload ids); health step meta: `slotStartedAt` (the health
 *   deadline applies per replica) and `pullSeenAt` (the last time a placement
 *   was pulling; the deadline counts from then).
 * - health_checking: driven by daemon reports stored on placements. A staged
 *   deployment (`hold_traffic`, docs/06 §6.5) stops in `ready` once healthy:
 *   built and started, not routed, until PromoteStagedDeployment.
 * - routing: the instance's active deployment switches (routes point at the
 *   new workloads), old workloads drain until `drainUntil`; active once every
 *   edge server applied the new NodeState (or after a timeout).
 * - failure: new workloads are removed, the old version keeps serving (a
 *   stopped stateful predecessor is started again).
 *
 * Slow lookups (tag → digest through the registry, branch → head commit
 * through GitHub) run BEFORE the row lock (prefetch()); under the lock the
 * deployment is re-checked and the results are used only if still needed.
 * So no HTTP call holds the deployment's lock or a transaction open.
 */
final readonly class AdvanceDeployment
{
    /** Extra allowance for image pulls before the health deadline starts biting. */
    private const int PULL_ALLOWANCE_SECONDS = 600;

    /**
     * A placement reported `pulling` this recently is still pulling: the
     * health deadline counts from the last moment one was (docs/04 §11
     * "Pulls"; launchroomd bounds pulls itself with idle and overall timeouts).
     */
    private const int PULL_FRESH_SECONDS = 180;

    public function __construct(
        private BuildOrchestrator $builds,
        private ReleaseRunner $releases,
        private ImageDigestResolver $digests,
        private Placer $placer,
        private RenderNodeState $renderNodeState,
        private MeshReadiness $meshReadiness,
        private EndDatabasePreparation $endPreparation,
    ) {}

    public function handle(int $deploymentId): void
    {
        $prefetched = $this->prefetch($deploymentId);

        $next = DB::transaction(function () use ($deploymentId, $prefetched): ?int {
            $deployment = Deployment::query()
                ->whereKey($deploymentId)
                ->with(['serviceInstance.service', 'environment.project', 'revision'])
                ->lockForUpdate()
                ->first();

            if ($deployment === null || $deployment->status->isTerminal() || $deployment->status === DeploymentStatus::Active) {
                return null;
            }

            // Several steps can complete in one call (e.g. queued → rolling_out).
            for ($i = 0; $i < 8 && $this->step($deployment, $prefetched); $i++);

            return $this->isFinished($deployment) ? $this->nextQueued($deployment) : null;
        });

        if ($next !== null) {
            dispatch(new AdvanceDeploymentJob($next));
        }
    }

    /**
     * The registry and Git lookups the next step needs, made without any lock
     * or transaction (read-only; the deployment is re-checked under the lock).
     */
    private function prefetch(int $deploymentId): PrefetchedInputs
    {
        $deployment = Deployment::query()
            ->whereKey($deploymentId)
            ->with(['serviceInstance.service', 'revision'])
            ->first();

        if ($deployment === null || ! in_array($deployment->status, [DeploymentStatus::Queued, DeploymentStatus::Building], true)) {
            return new PrefetchedInputs;
        }

        $spec = $this->spec($deployment);

        if (! $spec instanceof ServiceSpec || ($deployment->status === DeploymentStatus::Queued && $this->isBlocked($deployment))) {
            return new PrefetchedInputs;
        }

        if ($spec->kind->isBuilt()) {
            return new PrefetchedInputs(head: $this->builds->lookupHead($deployment));
        }

        $source = $spec->source();
        $reference = $source instanceof ImageSource ? ImageReference::tryParse($source->reference) : null;

        if ($deployment->image !== null || ! $reference instanceof ImageReference) {
            return new PrefetchedInputs;
        }

        try {
            return new PrefetchedInputs(image: $this->digests->resolve($reference));
        } catch (ImageResolutionFailed $e) {
            return new PrefetchedInputs(imageError: $e->getMessage());
        }
    }

    /**
     * Performs at most one transition. Returns whether the status changed.
     */
    private function step(Deployment $deployment, PrefetchedInputs $prefetched): bool
    {
        $spec = $this->spec($deployment);

        if (! $spec instanceof ServiceSpec) {
            return $this->fail($deployment, DeploymentFailureClass::ConfigError, 'The deployment has no configuration snapshot.');
        }

        // A server's desired state that can't be rendered because of this
        // deployment is never published (RenderNodeState); the deployment
        // can't roll out, so it fails with the reason.
        $renderError = $this->renderError($deployment);

        if ($renderError !== null) {
            return $this->fail($deployment, DeploymentFailureClass::ConfigError, $renderError);
        }

        try {
            return match ($deployment->status) {
                DeploymentStatus::Queued => $this->start($deployment, $spec, $prefetched),
                DeploymentStatus::Building => $this->afterExternalStep($deployment, $spec, DeploymentStepName::Build, $this->builds->advance($deployment, $prefetched->head)),
                DeploymentStatus::Releasing => $this->afterExternalStep($deployment, $spec, DeploymentStepName::Release, $this->releases->advance($deployment, EffectiveDeploy::for($spec, $deployment)->releaseCommand ?? [])),
                DeploymentStatus::RollingOut => $this->rollOut($deployment, $spec),
                DeploymentStatus::HealthChecking => $this->checkHealth($deployment, $spec),
                DeploymentStatus::Routing => $this->route($deployment, $spec),
                default => false,
            };
        } catch (DesiredStateInvalid $e) {
            return $this->fail($deployment, DeploymentFailureClass::ConfigError, $e->getMessage());
        }
    }

    /**
     * The render error of a server whose last render was refused because of
     * this deployment (servers.render_error.deployments), if any.
     */
    private function renderError(Deployment $deployment): ?string
    {
        if (! in_array($deployment->status, DeploymentStatus::inFlight(), true)) {
            return null;
        }

        $server = Server::query()
            ->where('team_id', $deployment->team_id)
            ->whereJsonContains('render_error->deployments', $deployment->public_id)
            ->orderBy('id')
            ->first();

        $message = $server?->render_error['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * Another deployment of the instance is in flight, or an older one is queued.
     */
    private function isBlocked(Deployment $deployment): bool
    {
        return Deployment::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->whereKeyNot($deployment->id)
            ->where(static function (Builder $query) use ($deployment): void {
                $query->whereIn('status', DeploymentStatus::values(DeploymentStatus::inFlight()))
                    ->orWhere(static fn (Builder $older) => $older->where('status', DeploymentStatus::Queued->value)->where('id', '<', $deployment->id));
            })
            ->exists();
    }

    private function start(Deployment $deployment, ServiceSpec $spec, PrefetchedInputs $prefetched): bool
    {
        // A database running alone for an import (docs/14 §12.9) gives way
        // to its first deploy once no import waits or runs.
        if (! DatabasePreparations::is($deployment)) {
            $this->endPreparation->handle($deployment->serviceInstance);
        }

        if ($this->isBlocked($deployment)) {
            return false;
        }

        if ($deployment->image === null && $spec->kind->isBuilt()) {
            $this->beginStep($deployment, DeploymentStepName::Build);
            $deployment->transitionTo(DeploymentStatus::Building);

            return true;
        }

        if ($deployment->image === null) {
            $source = $spec->source();
            $reference = $source instanceof ImageSource ? ImageReference::tryParse($source->reference) : null;

            if (! $reference instanceof ImageReference) {
                return $this->fail($deployment, DeploymentFailureClass::ConfigError, 'The service has no image to deploy.');
            }

            if ($prefetched->imageError !== null) {
                return $this->fail($deployment, DeploymentFailureClass::ConfigError, $prefetched->imageError);
            }

            try {
                // Resolved before the lock; resolving here is only the fallback.
                $pinned = $prefetched->image ?? $this->digests->resolve($reference);
            } catch (ImageResolutionFailed $e) {
                return $this->fail($deployment, DeploymentFailureClass::ConfigError, $e->getMessage());
            }

            $deployment->forceFill(['image' => $pinned->mirrored(config()->string('launchroom.images.mirror_prefix'))])->save();
        }

        return $this->releaseOrRollOut($deployment, $spec);
    }

    private function afterExternalStep(Deployment $deployment, ServiceSpec $spec, DeploymentStepName $name, StepOutcome $outcome): bool
    {
        if ($outcome->isPending()) {
            return false;
        }

        if ($outcome->isFailed()) {
            $this->finishStep($deployment, $name, DeploymentStepStatus::Failed, ['message' => $outcome->message]);

            return $this->fail($deployment, $outcome->failureClass ?? DeploymentFailureClass::BuildError, $outcome->message ?? 'The step failed.');
        }

        $this->finishStep($deployment, $name, DeploymentStepStatus::Succeeded, $outcome->message === null ? [] : ['message' => $outcome->message]);

        if ($name === DeploymentStepName::Build) {
            $deployment->refresh();

            if ($deployment->image === null) {
                return $this->fail($deployment, DeploymentFailureClass::BuildError, 'The build finished without an image.');
            }

            // A build for another platform ahead of a server move (docs/14
            // §13.3): the image is kept for the move's deployment to reuse
            // (same build key); nothing is rolled out.
            if (Prebuilds::platform($deployment) !== null) {
                $deployment->transitionTo(DeploymentStatus::Superseded);

                return true;
            }

            return $this->releaseOrRollOut($deployment, $spec);
        }

        return $this->beginRollOut($deployment);
    }

    private function releaseOrRollOut(Deployment $deployment, ServiceSpec $spec): bool
    {
        // Explicit release command, else the build summary's (docs/04 §13).
        // Rollbacks don't re-run it: migrations are not reverted (docs/04 §8).
        if ($deployment->trigger !== DeploymentTrigger::Rollback && EffectiveDeploy::for($spec, $deployment)->releaseCommand !== null) {
            $this->beginStep($deployment, DeploymentStepName::Release);
            $deployment->transitionTo(DeploymentStatus::Releasing);

            return true;
        }

        return $this->beginRollOut($deployment);
    }

    private function beginRollOut(Deployment $deployment): bool
    {
        $this->beginStep($deployment, DeploymentStepName::Rollout);
        $deployment->transitionTo(DeploymentStatus::RollingOut);

        return true;
    }

    private function rollOut(Deployment $deployment, ServiceSpec $spec): bool
    {
        if ($deployment->placements()->exists()) {
            return $this->waitForRollOut($deployment);
        }

        if ($spec->kind->isStateful()) {
            $stopped = $this->previousStopped($deployment);

            if ($stopped !== true) {
                // null: the deployment failed (status changed); false: still waiting.
                return $stopped === null;
            }
        }

        $rolling = $this->startsRolling($deployment, $spec);

        try {
            if ($rolling) {
                // Max-surge 1: the first new replica only (rollNext() places the rest).
                $serverId = $this->placer->plan($deployment, $spec, 1)->serverIds[0];
                $this->placer->createPlacement($deployment, $spec, 0, $serverId);
                $servers = [$serverId];
                $this->stepMeta($deployment, DeploymentStepName::Rollout, ['rolling' => true, 'drained' => []]);
            } else {
                $servers = $this->placer->place($deployment, $spec);
            }
        } catch (PlacementFailed $e) {
            $this->finishStep($deployment, DeploymentStepName::Rollout, DeploymentStepStatus::Failed, ['message' => $e->getMessage()]);

            return $this->fail($deployment, DeploymentFailureClass::PlacementError, $e->getMessage());
        }

        $this->render($servers, $deployment);
        $this->stepMeta($deployment, DeploymentStepName::Rollout, ['servers' => $this->serverPublicIds($servers)]);

        return $servers === [] && $this->completeRollOut($deployment);
    }

    private function waitForRollOut(Deployment $deployment): bool
    {
        $placements = $deployment->placements()->get();
        $broken = $placements->first(static fn (Placement $placement): bool => $placement->phase->isStopped());

        if ($broken !== null) {
            $message = $broken->message ?? "The container {$broken->phase->value}.";
            $this->finishStep($deployment, DeploymentStepName::Rollout, DeploymentStepStatus::Failed, ['message' => $message]);

            return $this->fail($deployment, str_starts_with($message, 'pull failed') ? DeploymentFailureClass::Transient : DeploymentFailureClass::RuntimeError, $message);
        }

        if ($placements->every(static fn (Placement $placement): bool => $placement->phase->isObserved())) {
            return $this->completeRollOut($deployment);
        }

        $step = $this->findStep($deployment, DeploymentStepName::Rollout);
        $latest = $placements->filter(static fn (Placement $placement): bool => $placement->phase === WorkloadPhase::Pulling)->max('observed_at');
        $since = $latest instanceof CarbonInterface && $step?->started_at !== null && $latest->greaterThan($step->started_at) ? $latest : $step?->started_at;

        if ($since !== null && $since->copy()->addSeconds(self::PULL_ALLOWANCE_SECONDS)->isPast()) {
            $message = 'The server stopped reporting progress while preparing the image.';
            $this->finishStep($deployment, DeploymentStepName::Rollout, DeploymentStepStatus::Failed, ['message' => $message]);

            return $this->fail($deployment, DeploymentFailureClass::Transient, $message);
        }

        return false;
    }

    private function completeRollOut(Deployment $deployment): bool
    {
        if (! $this->isRolling($deployment)) {
            $this->finishStep($deployment, DeploymentStepName::Rollout, DeploymentStepStatus::Succeeded);
        }

        return $this->beginHealthCheck($deployment);
    }

    /**
     * A rolling update replaces serving replicas of a stateless service one
     * at a time; single replicas, first deploys, staged deployments and
     * stateful services start all their replicas at once.
     */
    private function startsRolling(Deployment $deployment, ServiceSpec $spec): bool
    {
        return ! $spec->kind->isStateful()
            && ! $deployment->hold_traffic
            && $spec->scaling->replicas > 1
            && Placement::query()
                ->where('service_instance_id', $deployment->service_instance_id)
                ->where('deployment_id', '!=', $deployment->id)
                ->where('role', WorkloadRole::Serving->value)
                ->exists();
    }

    private function isRolling(Deployment $deployment): bool
    {
        return ($this->stepMeta($deployment, DeploymentStepName::Rollout)['rolling'] ?? false) === true;
    }

    /**
     * Every new replica placed so far is healthy (and routed): drain as many
     * old replicas as new ones are healthy, then place the next new replica.
     *
     * @param  Collection<int, Placement>  $placements  the deployment's placements
     */
    private function rollNext(Deployment $deployment, ServiceSpec $spec, Collection $placements): bool
    {
        $meta = $this->stepMeta($deployment, DeploymentStepName::Rollout);
        /** @var list<string> $drained */
        $drained = is_array($meta['drained'] ?? null) ? array_values(array_filter($meta['drained'], is_string(...))) : [];
        $newServers = array_values($placements->map(static fn (Placement $placement): int => $placement->server_id)->all());

        try {
            $serverId = $this->placer->plan($deployment, $spec, 1, $newServers)->serverIds[0];
        } catch (PlacementFailed $e) {
            $this->finishStep($deployment, DeploymentStepName::Health, DeploymentStepStatus::Failed, ['message' => $e->getMessage()]);

            return $this->fail($deployment, DeploymentFailureClass::PlacementError, $e->getMessage());
        }

        // Old replicas on the servers that now run new ones go first.
        $old = Placement::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('deployment_id', '!=', $deployment->id)
            ->where('role', WorkloadRole::Serving->value)
            ->orderByDesc('slot')
            ->get()
            ->sortBy(static fn (Placement $placement): int => in_array($placement->server_id, $newServers, true) ? 0 : 1)
            ->values();

        $drain = $old->take(max(0, $placements->count() - count($drained)));
        $touched = [$serverId, ...$drain->pluck('server_id')->all()];

        if ($drain->isNotEmpty()) {
            Placement::query()->whereKey($drain->modelKeys())->update([
                'role' => WorkloadRole::Draining->value,
                'drain_until' => now()->addSeconds($spec->deploy->drainSeconds),
            ]);
            $drained = [...$drained, ...$drain->map(static fn (Placement $placement): string => $placement->workload_id)->values()->all()];
        }

        $slot = $placements->map(static fn (Placement $placement): int => $placement->slot)->max() + 1;
        $this->placer->createPlacement($deployment, $spec, $slot, $serverId);
        $this->stepMeta($deployment, DeploymentStepName::Rollout, ['drained' => $drained]);
        $this->stepMeta($deployment, DeploymentStepName::Health, ['slotStartedAt' => now()->toIso8601String(), 'placed' => $slot + 1]);

        // The edges route the healthy new replicas next to the old ones.
        $this->render($this->ids([...$touched, ...$this->edgeServers($deployment->team_id)]), $deployment);

        return false;
    }

    /**
     * Stop-then-start for stateful services (docs/04 §8): the previous
     * workloads drain immediately; the new one starts only once the daemon
     * reported them stopped. Returns true once nothing runs anymore, false
     * while waiting, null when it gave up (the deployment failed).
     */
    private function previousStopped(Deployment $deployment): ?bool
    {
        $previous = Placement::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('deployment_id', '!=', $deployment->id)
            ->lockForUpdate()
            ->get();

        if ($previous->isEmpty()) {
            return true;
        }

        $toStop = $previous->filter(static fn (Placement $placement): bool => $placement->role === WorkloadRole::Serving);

        if ($toStop->isNotEmpty()) {
            Placement::query()->whereKey($toStop->modelKeys())->update(['role' => WorkloadRole::Draining->value, 'drain_until' => now()]);
            $this->stepMeta($deployment, DeploymentStepName::Rollout, ['stopping' => $toStop->pluck('workload_id')->values()->all(), 'stoppingSince' => now()->toIso8601String()]);
            $this->render($this->ids($toStop->pluck('server_id')->all()));

            return false;
        }

        if ($previous->every(static fn (Placement $placement): bool => $placement->phase->isStopped())) {
            return true;
        }

        $since = $this->stepMeta($deployment, DeploymentStepName::Rollout)['stoppingSince'] ?? null;

        if (is_string($since) && CarbonImmutable::parse($since)->addSeconds(config()->integer('launchroom.deployments.stop_timeout_seconds'))->isPast()) {
            $this->fail($deployment, DeploymentFailureClass::RuntimeError, 'The previous version did not stop in time, so the new one was not started (a database must never run twice on the same data).');

            return null;
        }

        return false;
    }

    private function beginHealthCheck(Deployment $deployment): bool
    {
        $this->beginStep($deployment, DeploymentStepName::Health);
        $deployment->transitionTo(DeploymentStatus::HealthChecking);

        return true;
    }

    private function checkHealth(Deployment $deployment, ServiceSpec $spec): bool
    {
        $placements = $deployment->placements()->get();

        if ($placements->isEmpty()) {
            // Scaled to zero: nothing to check.
            $this->finishStep($deployment, DeploymentStepName::Health, DeploymentStepStatus::Skipped);

            return $this->beginRouting($deployment);
        }

        $broken = $placements->first(static fn (Placement $placement): bool => in_array($placement->phase, [WorkloadPhase::Failed, WorkloadPhase::Exited, WorkloadPhase::Removed], true));

        if ($broken !== null) {
            $message = $broken->message ?? "The container {$broken->phase->value}.";
            $this->finishStep($deployment, DeploymentStepName::Health, DeploymentStepStatus::Failed, ['message' => $message]);

            // The daemon reports registry trouble as "pull failed: …" (docs/04 §6: transient).
            return $this->fail($deployment, str_starts_with($message, 'pull failed') ? DeploymentFailureClass::Transient : DeploymentFailureClass::RuntimeError, $message);
        }

        // Healthy and reachable: its servers' cross-server dependencies
        // admit it on the mesh (docs/05 §3), else requests touching them fail.
        $allHealthy = $placements->every(fn (Placement $placement): bool => $placement->phase === WorkloadPhase::Healthy && $this->meshReadiness->ready($placement));

        if ($allHealthy && $placements->count() < $spec->scaling->replicas && $this->isRolling($deployment)) {
            return $this->rollNext($deployment, $spec, $placements);
        }

        if ($allHealthy) {
            if ($this->isRolling($deployment)) {
                $this->finishStep($deployment, DeploymentStepName::Rollout, DeploymentStepStatus::Succeeded);
            }
            $this->finishStep($deployment, DeploymentStepName::Health, DeploymentStepStatus::Succeeded);

            if ($deployment->hold_traffic) {
                // Staged: the previous version keeps serving until Promote.
                $deployment->transitionTo(DeploymentStatus::Ready);

                return true;
            }

            return $this->beginRouting($deployment);
        }

        // Rolling updates: the deadline applies to each new replica.
        $meta = $this->stepMeta($deployment, DeploymentStepName::Health);
        $slotStarted = $meta['slotStartedAt'] ?? null;
        $started = is_string($slotStarted) ? CarbonImmutable::parse($slotStarted) : $this->findStep($deployment, DeploymentStepName::Health)?->started_at;

        // A large first pull (the registry mirror fetching it, then the
        // download) must not eat the health deadline while it moves.
        $pulling = $placements->contains(static fn (Placement $placement): bool => $placement->phase === WorkloadPhase::Pulling
            && $placement->observed_at !== null
            && $placement->observed_at->greaterThan(now()->subSeconds(self::PULL_FRESH_SECONDS)));
        $pullSeen = is_string($meta['pullSeenAt'] ?? null) ? CarbonImmutable::parse($meta['pullSeenAt']) : null;

        if ($pulling && (! $pullSeen instanceof CarbonImmutable || $pullSeen->lessThan(now()->subSeconds(30)))) {
            $pullSeen = CarbonImmutable::now();
            $this->stepMeta($deployment, DeploymentStepName::Health, ['pullSeenAt' => $pullSeen->toIso8601ZuluString()]);
        }

        if ($pullSeen instanceof CarbonImmutable && ($started === null || $pullSeen->greaterThan($started))) {
            $started = $pullSeen;
        }
        $deadline = EffectiveDeploy::for($spec, $deployment)->healthcheck->deadlineSeconds() + self::PULL_ALLOWANCE_SECONDS;

        if ($started !== null && $started->copy()->addSeconds($deadline)->isPast()) {
            $unhealthy = $placements->first(static fn (Placement $placement): bool => $placement->phase !== WorkloadPhase::Healthy);
            $message = $unhealthy === null
                ? 'The new version is healthy, but the servers it depends on did not admit it on the mesh in time (their firewall update was not applied).'
                : 'The new version did not become healthy in time'.($unhealthy->message !== null ? ": {$unhealthy->message}" : '.');
            $this->finishStep($deployment, DeploymentStepName::Health, DeploymentStepStatus::Failed, ['message' => $message]);

            return $this->fail($deployment, DeploymentFailureClass::HealthTimeout, $message);
        }

        return false;
    }

    private function beginRouting(Deployment $deployment): bool
    {
        $this->beginStep($deployment, DeploymentStepName::Route);
        $deployment->transitionTo(DeploymentStatus::Routing);

        return true;
    }

    private function route(Deployment $deployment, ServiceSpec $spec): bool
    {
        $meta = $this->stepMeta($deployment, DeploymentStepName::Route);

        if (! isset($meta['versions'])) {
            $instance = $deployment->serviceInstance;
            $previousId = $instance->active_deployment_id;

            $instance->forceFill(['active_deployment_id' => $deployment->id])->save();

            $previous = Placement::query()
                ->where('service_instance_id', $instance->id)
                ->where('deployment_id', '!=', $deployment->id)
                ->where('role', WorkloadRole::Serving->value)
                ->get();

            // The drain countdown starts once the edges route to the new
            // version (below); until then the old one keeps running, bounded
            // by the routing timeout.
            Placement::query()->whereKey($previous->modelKeys())->update([
                'role' => WorkloadRole::Draining->value,
                'drain_until' => now()->addSeconds($spec->deploy->drainSeconds + config()->integer('launchroom.deployments.routing_timeout_seconds')),
            ]);

            $edges = $this->edgeServers($deployment->team_id);
            $servers = $this->ids([
                ...$deployment->placements()->pluck('server_id')->all(),
                ...$previous->pluck('server_id')->all(),
                ...$edges,
            ]);

            $versions = [];

            foreach ($this->render($servers, $deployment) as $serverId => $version) {
                if (in_array($serverId, $edges, true)) {
                    $versions[(string) $serverId] = $version;
                }
            }

            $this->stepMeta($deployment, DeploymentStepName::Route, ['versions' => (object) $versions, 'previous' => $previousId]);
            $meta = $this->stepMeta($deployment, DeploymentStepName::Route);
        }

        /** @var array<string, int> $versions */
        $versions = is_array($meta['versions'] ?? null) ? $meta['versions'] : [];
        $observed = Server::query()->whereKey(array_map(intval(...), array_keys($versions)))->pluck('observed_version', 'id')->all();
        $applied = true;

        foreach ($versions as $serverId => $version) {
            $current = $observed[(int) $serverId] ?? 0;
            $applied = $applied && is_numeric($current) && (int) $current >= $version;
        }

        $started = $this->findStep($deployment, DeploymentStepName::Route)?->started_at;
        $timedOut = $started !== null && $started->copy()->addSeconds(config()->integer('launchroom.deployments.routing_timeout_seconds'))->isPast();

        if (! $applied && ! $timedOut) {
            return false;
        }

        $this->finishStep($deployment, DeploymentStepName::Route, DeploymentStepStatus::Succeeded, $applied ? [] : ['note' => 'Some edge servers haven’t applied the new routes yet. They apply them when they reconnect.']);
        $deployment->transitionTo(DeploymentStatus::Active);
        $this->startDrain($deployment, $spec);

        $previousId = $meta['previous'] ?? null;

        if ($previousId === null && $spec->deploy->seedCommand !== null) {
            // First deploy of this instance: seed it once.
            $this->releases->seed($deployment, $spec->deploy->seedCommand);
        }

        if (is_int($previousId)) {
            $previous = Deployment::query()->whereKey($previousId)->lockForUpdate()->first();

            if ($previous !== null && $previous->status === DeploymentStatus::Active) {
                $previous->forceFill(['superseded_by_id' => $deployment->id]);
                $previous->transitionTo(DeploymentStatus::Superseded);
            }
        }

        return true;
    }

    /**
     * Old workloads drain for `drainSeconds` from now: the edges route to the
     * new version, so in-flight requests get the full drain period (docs/04 §8).
     */
    private function startDrain(Deployment $deployment, ServiceSpec $spec): void
    {
        $until = now()->addSeconds($spec->deploy->drainSeconds);
        $draining = Placement::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('deployment_id', '!=', $deployment->id)
            ->where('role', WorkloadRole::Draining->value)
            ->where('drain_until', '>', $until)
            ->get();

        if ($draining->isEmpty()) {
            return;
        }

        Placement::query()->whereKey($draining->modelKeys())->update(['drain_until' => $until]);
        $this->render($this->ids($draining->pluck('server_id')->all()));
    }

    /**
     * New workloads are removed and the old version keeps serving. A stateful
     * predecessor stopped for this rollout is started again (as new workloads
     * of its deployment).
     */
    private function fail(Deployment $deployment, DeploymentFailureClass $class, string $message): bool
    {
        if ($deployment->status === DeploymentStatus::Building) {
            $this->builds->cancel($deployment);
        }

        if ($deployment->status === DeploymentStatus::Releasing) {
            $this->releases->cancel($deployment);
        }

        $drained = $this->stepMeta($deployment, DeploymentStepName::Rollout)['drained'] ?? [];
        foreach ($deployment->steps()->where('status', DeploymentStepStatus::Running->value)->get() as $step) {
            $this->finishStep($deployment, $step->name, DeploymentStepStatus::Failed, ['message' => $message]);
        }
        $deployment->transitionTo(DeploymentStatus::Failed, $class, $message);

        $servers = $this->ids($deployment->placements()->pluck('server_id')->all());
        $deployment->placements()->delete();

        // A rolling update drained old replicas for healthy new ones: they serve again.
        if (is_array($drained) && $drained !== []) {
            $restore = Placement::query()
                ->where('service_instance_id', $deployment->service_instance_id)
                ->whereIn('workload_id', array_values(array_filter($drained, is_string(...))))
                ->where('role', WorkloadRole::Draining->value)
                ->get();

            Placement::query()->whereKey($restore->modelKeys())->update(['role' => WorkloadRole::Serving->value, 'drain_until' => null]);
            $servers = $this->ids([...$servers, ...$restore->pluck('server_id')->all(), ...$this->edgeServers($deployment->team_id)]);
        }

        $instance = $deployment->serviceInstance;

        if ($instance->service->kind->isStateful() && $instance->active_deployment_id !== null && $instance->active_deployment_id !== $deployment->id) {
            $stopped = Placement::query()
                ->where('deployment_id', $instance->active_deployment_id)
                ->where('role', WorkloadRole::Draining->value)
                ->get();

            foreach ($stopped as $placement) {
                $servers[] = $placement->server_id;
                $placement->delete();

                Placement::query()->create([
                    'team_id' => $placement->team_id,
                    'service_instance_id' => $placement->service_instance_id,
                    'deployment_id' => $placement->deployment_id,
                    'server_id' => $placement->server_id,
                    'slot' => $placement->slot,
                    'workload_id' => PublicId::generate('wl'),
                    'memory_mb' => $placement->memory_mb,
                    'cpus' => $placement->cpus,
                ]);
            }
        }

        $this->render($this->ids($servers));

        return true;
    }

    private function isFinished(Deployment $deployment): bool
    {
        return $deployment->status->isTerminal() || $deployment->status === DeploymentStatus::Active;
    }

    private function nextQueued(Deployment $deployment): ?int
    {
        $next = Deployment::query()
            ->where('service_instance_id', $deployment->service_instance_id)
            ->where('status', DeploymentStatus::Queued->value)
            ->orderBy('id')
            ->value('id');

        return is_int($next) ? $next : null;
    }

    private function spec(Deployment $deployment): ?ServiceSpec
    {
        return $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
    }

    /**
     * Renders the NodeStates of $serverIds (approved servers only).
     *
     * @param  list<int>  $serverIds
     * @return array<int, int> server id => rendered version
     *
     * @throws DesiredStateInvalid when a render was refused because of $for
     */
    private function render(array $serverIds, ?Deployment $for = null): array
    {
        if ($serverIds === []) {
            return [];
        }

        $versions = [];
        $failure = null;
        $servers = Server::query()->whereKey(array_values(array_unique($serverIds)))->orderBy('id')->get();

        foreach ($servers as $server) {
            if ($server->status->isApproved()) {
                $render = $this->renderNodeState->handle($server);
                $versions[$server->id] = $render->version->version;

                if ($for instanceof Deployment && $render->failed() && in_array($for->id, $render->deployments, true)) {
                    $failure ??= $render->error;
                }
            }
        }

        if ($failure !== null) {
            throw new DesiredStateInvalid($failure);
        }

        return $versions;
    }

    /**
     * Distinct integer ids.
     *
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $values)));
    }

    /**
     * @return list<int>
     */
    private function edgeServers(int $teamId): array
    {
        /** @var list<int> $ids */
        $ids = Server::query()
            ->where('team_id', $teamId)
            ->where('status', ServerStatus::Online->value)
            ->whereJsonContains('roles', ServerRole::Edge->value)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * @param  list<int>  $serverIds
     * @return list<string>
     */
    private function serverPublicIds(array $serverIds): array
    {
        /** @var list<string> $ids */
        $ids = Server::query()->whereKey($serverIds)->orderBy('id')->pluck('public_id')->all();

        return $ids;
    }

    private function findStep(Deployment $deployment, DeploymentStepName $name): ?DeploymentStep
    {
        return DeploymentStep::query()->where('deployment_id', $deployment->id)->where('name', $name->value)->first();
    }

    private function beginStep(Deployment $deployment, DeploymentStepName $name): void
    {
        DeploymentStep::begin($deployment, $name);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finishStep(Deployment $deployment, DeploymentStepName $name, DeploymentStepStatus $status, array $meta = []): void
    {
        DeploymentStep::finish($deployment, $name, $status, $meta);
    }

    /**
     * Reads (and with $merge, updates) a step's bookkeeping.
     *
     * @param  array<string, mixed>|null  $merge
     * @return array<string, mixed>
     */
    private function stepMeta(Deployment $deployment, DeploymentStepName $name, ?array $merge = null): array
    {
        $step = $this->findStep($deployment, $name);

        if (! $step instanceof DeploymentStep) {
            return [];
        }

        if ($merge !== null) {
            $step->forceFill(['meta' => [...$step->meta, ...$merge]])->save();
        }

        return $step->meta;
    }
}

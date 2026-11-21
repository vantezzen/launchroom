<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Contracts\Git\ReadsCommitChecks;
use App\Data\Deployments\DeploymentChecksData;
use App\Data\Git\CommitCheckData;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\CheckGateState;
use App\Enums\DeploymentStatus;
use App\Exceptions\CommitChecksUnavailable;
use App\Jobs\AdvanceDeploymentJob;
use App\Models\Deployment;
use App\Services\Git\GitProviders;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads the CI checks of a waiting push deployment's commit and settles the
 * gate when it can (D111, docs/06 §2.5): every check passed → it starts;
 * no check within the grace period → it starts and says so; a check failed
 * or the deadline passed → cancelled with the reason (nothing ran; the old
 * version keeps serving). A read that fails keeps it waiting until the
 * deadline (fail closed). Webhooks and the one-minute fallback call this;
 * the provider is read before the row lock and the verdict applied under it,
 * so duplicates and races are harmless.
 */
final readonly class EvaluateDeploymentChecks
{
    public function __construct(
        private GitProviders $providers,
    ) {}

    public function handle(int $deploymentId): void
    {
        $deployment = Deployment::query()->with('serviceInstance.service')->find($deploymentId);

        if (! $deployment instanceof Deployment || ! $deployment->waitsForChecks()) {
            return;
        }

        $read = $this->read($deployment);

        $start = DB::transaction(function () use ($deploymentId, $read): ?int {
            $deployment = Deployment::query()->whereKey($deploymentId)->lockForUpdate()->first();

            if (! $deployment instanceof Deployment || ! $deployment->waitsForChecks() || ! $deployment->checks instanceof DeploymentChecksData) {
                return null;
            }

            $now = now();
            $gate = is_string($read) ? $deployment->checks->withProblem($read, $now) : $deployment->checks->withChecks($read, $now);
            $verdict = $this->verdict($gate, ! is_string($read), $now);
            $deployment->checks = $verdict instanceof CheckGateState ? $gate->settle($verdict) : $gate;

            if ($verdict === CheckGateState::Failed || $verdict === CheckGateState::TimedOut) {
                $deployment->forceFill(['failure_message' => mb_substr($this->reason($deployment, $deployment->checks), 0, Deployment::FAILURE_MESSAGE_LIMIT)]);
                $deployment->transitionTo(DeploymentStatus::Cancelled);
                $deployment->save();

                return Deployment::nextQueuedId($deployment->service_instance_id);
            }

            $deployment->save();

            return $verdict instanceof CheckGateState && $verdict->letsDeploy() ? $deployment->id : null;
        });

        if ($start !== null) {
            dispatch(new AdvanceDeploymentJob($start));
        }
    }

    /**
     * The commit's checks, or why they couldn't be read.
     *
     * @return list<CommitCheckData>|string
     */
    private function read(Deployment $deployment): array|string
    {
        $source = $deployment->serviceInstance->service->source;
        $provider = $source instanceof GitSource ? $this->providers->forSource($source) : null;

        if (! $source instanceof GitSource || ! $provider instanceof ReadsCommitChecks || $deployment->commit_sha === null) {
            return 'launchroom can no longer read the checks of this service’s repository.';
        }

        try {
            return $provider->commitChecks(RepositoryRef::fromSource($deployment->team_id, $source), $deployment->commit_sha);
        } catch (CommitChecksUnavailable $e) {
            return $e->getMessage();
        }
    }

    /** The final state, or null to keep waiting. */
    private function verdict(DeploymentChecksData $gate, bool $read, CarbonInterface $now): ?CheckGateState
    {
        if ($read && $gate->failedChecks() !== []) {
            return CheckGateState::Failed;
        }

        if ($read && $gate->checks !== [] && $gate->pendingChecks() === []) {
            return CheckGateState::Passed;
        }

        if ($read && $gate->checks === [] && $now->greaterThanOrEqualTo($gate->sinceAt()->addSeconds(config()->integer('launchroom.deployments.checks_grace_seconds')))) {
            return CheckGateState::None;
        }

        return $now->greaterThanOrEqualTo($gate->deadlineAt()) ? CheckGateState::TimedOut : null;
    }

    /** The cancelled deployment's summary line. */
    private function reason(Deployment $deployment, DeploymentChecksData $gate): string
    {
        $commit = $deployment->commit_sha === null ? 'this commit' : substr($deployment->commit_sha, 0, 7);

        if ($gate->state === CheckGateState::Failed) {
            $failed = $gate->failedChecks();

            return count($failed) === 1
                ? "Not deployed: the check “{$failed[0]->name}” failed on {$commit}."
                : 'Not deployed: '.count($failed)." checks failed on {$commit} ({$this->names($failed)}).";
        }

        $minutes = (int) round($gate->sinceAt()->diffInMinutes($gate->deadlineAt(), true));

        if ($gate->problem !== null) {
            return "Not deployed: launchroom couldn’t read the checks of {$commit} for {$minutes} minutes. {$gate->problem}";
        }

        $pending = $gate->pendingChecks();

        return $pending === []
            ? "Not deployed: no checks finished on {$commit} within {$minutes} minutes."
            : "Not deployed: checks were still running on {$commit} after {$minutes} minutes ({$this->names($pending)}).";
    }

    /**
     * "test, lint": the first five names.
     *
     * @param  list<CommitCheckData>  $checks
     */
    private function names(array $checks): string
    {
        $names = [];

        foreach (array_slice($checks, 0, 5) as $check) {
            $names[] = $check->name;
        }

        return implode(', ', $names);
    }
}

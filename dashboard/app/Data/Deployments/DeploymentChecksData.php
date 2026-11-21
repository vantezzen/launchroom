<?php

declare(strict_types=1);

namespace App\Data\Deployments;

use App\Data\Git\CommitCheckData;
use App\Enums\CheckGateState;
use App\Enums\Git\CommitCheckState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * `deployments.checks` (D111, docs/06 §2.5): a push deployment of an
 * environment that deploys after checks pass. The state changes only through
 * {@see self::settle()}, which follows CheckGateState's transition table.
 * Also the API's `DeploymentResource.checks`.
 */
final class DeploymentChecksData extends Data
{
    /**
     * @param  list<CommitCheckData>  $checks  the checks as last read, launchroom's own reports left out
     */
    public function __construct(
        public CheckGateState $state,
        /** When the deployment started waiting (ISO 8601). */
        public string $since,
        /** When it gives up (ISO 8601). */
        public string $deadline,
        public array $checks = [],
        /** Who chose Deploy now (`skipped`). */
        public ?string $skippedBy = null,
        /** Why the last read failed while waiting ("GitHub didn't answer"); cleared by the next good read. */
        public ?string $problem = null,
        /** When the checks were last read (ISO 8601). */
        public ?string $checkedAt = null,
    ) {}

    public static function waiting(CarbonInterface $now, int $timeoutMinutes): self
    {
        return new self(CheckGateState::Waiting, $now->toIso8601String(), $now->addMinutes($timeoutMinutes)->toIso8601String());
    }

    /** A plain git server: nothing to wait for, the deployment starts. */
    public static function unsupported(CarbonInterface $now): self
    {
        return new self(CheckGateState::Unsupported, $now->toIso8601String(), $now->toIso8601String());
    }

    public function isWaiting(): bool
    {
        return $this->state === CheckGateState::Waiting;
    }

    public function deadlineAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->deadline);
    }

    public function sinceAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->since);
    }

    /**
     * The gate after a read of the checks (still waiting, or settled).
     *
     * @param  list<CommitCheckData>  $checks
     */
    public function withChecks(array $checks, CarbonInterface $now): self
    {
        return new self($this->state, $this->since, $this->deadline, $checks, $this->skippedBy, null, $now->toIso8601String());
    }

    public function withProblem(string $problem, CarbonInterface $now): self
    {
        return new self($this->state, $this->since, $this->deadline, $this->checks, $this->skippedBy, $problem, $now->toIso8601String());
    }

    /**
     * The final state, through the transition table (a settled gate never changes).
     */
    public function settle(CheckGateState $to, ?string $skippedBy = null): self
    {
        $this->state->assertCanTransitionTo($to);

        return new self($to, $this->since, $this->deadline, $this->checks, $skippedBy ?? $this->skippedBy, $this->problem, $this->checkedAt);
    }

    /**
     * @return list<CommitCheckData>
     */
    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks, static fn (CommitCheckData $check): bool => $check->state === CommitCheckState::Failed));
    }

    /**
     * @return list<CommitCheckData>
     */
    public function pendingChecks(): array
    {
        return array_values(array_filter($this->checks, static fn (CommitCheckData $check): bool => $check->state === CommitCheckState::Pending));
    }
}

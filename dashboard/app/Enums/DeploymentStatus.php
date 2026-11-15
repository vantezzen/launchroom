<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Attributes\SharedFacts;
use App\Enums\Concerns\HasTransitions;
use App\Enums\Contracts\HasTransitionTable;

/**
 * The deployment state machine (docs/04 §6). Every status change goes through
 * Deployment::transitionTo(), which validates against this table and emits
 * DeploymentStatusChanged.
 *
 * queued → building? → releasing? → rolling_out → health_checking → [ready →] routing → active
 * any in-flight status → failed | cancelled; queued/building → superseded
 * (a newer deployment of the same service instance collapses older ones);
 * active → superseded (a newer deployment went live).
 *
 * `ready`: a staged deployment (docs/06 §6.5, `hold_traffic`) is healthy and
 * waits for "Promote" to route traffic to it; the previous version keeps
 * serving meanwhile. It can be cancelled (its workloads are removed).
 */
#[SharedFacts('isCancellable', 'isUnfinished')]
enum DeploymentStatus: string implements HasTransitionTable
{
    use HasTransitions;

    case Queued = 'queued';
    case Building = 'building';
    case Releasing = 'releasing';
    case RollingOut = 'rolling_out';
    case HealthChecking = 'health_checking';
    case Ready = 'ready';
    case Routing = 'routing';
    case Active = 'active';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Building, self::Releasing, self::RollingOut, self::Failed, self::Cancelled, self::Superseded],
            self::Building => [self::Releasing, self::RollingOut, self::Failed, self::Cancelled, self::Superseded],
            self::Releasing => [self::RollingOut, self::Failed, self::Cancelled],
            self::RollingOut => [self::HealthChecking, self::Failed, self::Cancelled],
            self::HealthChecking => [self::Routing, self::Ready, self::Failed, self::Cancelled],
            self::Ready => [self::Routing, self::Failed, self::Cancelled],
            // Traffic already moves: users can't cancel (isCancellable, docs/04 §8),
            // but removing the service tears it down (TearDownInstance).
            self::Routing => [self::Active, self::Failed, self::Cancelled],
            self::Active => [self::Superseded],
            self::Failed, self::Cancelled, self::Superseded => [],
        };
    }

    /**
     * Started and not yet finished: at most one per service instance.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Building, self::Releasing, self::RollingOut, self::HealthChecking, self::Ready, self::Routing];
    }

    /**
     * Started and not healthy yet: a hold cancels these (docs/14 §12.5).
     *
     * @return list<self>
     */
    public static function starting(): array
    {
        return [self::Building, self::Releasing, self::RollingOut, self::HealthChecking];
    }

    /**
     * Unfinished and traffic hasn't switched to it: stopping the service
     * cancels these. Routing runs to the end (docs/04 §8).
     *
     * @return list<self>
     */
    public static function beforeTraffic(): array
    {
        return [self::Queued, ...self::starting(), self::Ready];
    }

    /**
     * Queued or in flight: the deployment may still go live.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Queued, ...self::inFlight()];
    }

    public function isFinished(): bool
    {
        return $this->isTerminal() || $this === self::Active;
    }

    /**
     * Nothing more happens without someone acting: finished, live, or staged
     * and waiting for Promote. Ready is still in flight (it holds the
     * instance's slot) but settled (the API's `finished`, SSE end).
     */
    public function isSettled(): bool
    {
        return $this->isTerminal() || $this === self::Active || $this === self::Ready;
    }

    /**
     * Cancellable: queued, building (nothing runs yet), or ready (its
     * workloads never got traffic). Other steps run to success or failure
     * (docs/04 §8).
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Queued, self::Building, self::Ready], true);
    }

    public function isUnfinished(): bool
    {
        return in_array($this, self::unfinished(), true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, self::inFlight(), true);
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(static fn (self $status): string => $status->value, $statuses);
    }

    /**
     * The status word (design-system §3.3): every in-flight stage reads
     * "Deploying"; {@see stage()} is the detail beside it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Building, self::Releasing, self::RollingOut, self::HealthChecking, self::Routing => 'Deploying',
            self::Ready => 'Ready to promote',
            self::Active => 'Live',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Superseded => 'Superseded',
        };
    }

    public function stage(): string
    {
        return match ($this) {
            self::Building => 'Building',
            self::Releasing => 'Running release command',
            self::RollingOut => 'Rolling out',
            self::HealthChecking => 'Health checking',
            self::Routing => 'Routing traffic',
            default => $this->label(),
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Queued, self::Building, self::Releasing, self::RollingOut, self::HealthChecking, self::Routing => StatusTone::Progress,
            self::Ready => StatusTone::Staged,
            self::Active => StatusTone::Active,
            self::Failed => StatusTone::Failed,
            self::Cancelled, self::Superseded => StatusTone::Idle,
        };
    }
}

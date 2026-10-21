<?php

declare(strict_types=1);

namespace App\Enums\Contracts;

use App\Exceptions\InvalidStatusTransition;
use BackedEnum;

/**
 * A status enum that owns its transition table (docs/10 §2.2 rule 3).
 *
 * Implement `allowedTransitions()` with a `match ($this)` and `use
 * HasTransitions` for the rest. Status changes must go through the model's
 * single transition method, which calls `assertCanTransitionTo()`.
 */
interface HasTransitionTable extends BackedEnum
{
    /**
     * The statuses reachable from this one. Empty for terminal statuses.
     *
     * @return list<static>
     */
    public function allowedTransitions(): array;

    public function canTransitionTo(self $to): bool;

    /**
     * @throws InvalidStatusTransition
     */
    public function assertCanTransitionTo(self $to): void;

    public function isTerminal(): bool;
}

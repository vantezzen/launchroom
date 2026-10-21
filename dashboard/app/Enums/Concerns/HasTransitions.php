<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use App\Enums\Contracts\HasTransitionTable;
use App\Exceptions\InvalidStatusTransition;

/**
 * Default behaviour for {@see HasTransitionTable} enums.
 *
 * @phpstan-require-implements HasTransitionTable
 */
trait HasTransitions
{
    public function canTransitionTo(HasTransitionTable $to): bool
    {
        return $to instanceof self && in_array($to, $this->allowedTransitions(), true);
    }

    public function assertCanTransitionTo(HasTransitionTable $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidStatusTransition::between($this, $to);
        }
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}

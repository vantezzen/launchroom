<?php

declare(strict_types=1);

namespace App\Exceptions;

use BackedEnum;
use DomainException;

/**
 * Raised when a status change isn't in the enum's transition table. The
 * message is safe to show to users (docs/10 §2.2 rule 10).
 */
final class InvalidStatusTransition extends DomainException
{
    private function __construct(
        string $message,
        public readonly BackedEnum $from,
        public readonly BackedEnum $to,
    ) {
        parent::__construct($message);
    }

    public static function between(BackedEnum $from, BackedEnum $to): self
    {
        return new self(
            sprintf('Cannot change status from "%s" to "%s".', $from->value, $to->value),
            $from,
            $to,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainRuleViolation;

/**
 * A domain operation's typed "no" with a user-facing reason. Workflows
 * branch on it; the user-facing Action wrapping the operation throws it as
 * a DomainRuleViolation ({@see orThrow}).
 */
final readonly class Refusal
{
    public function __construct(
        public string $message,
        public string $field = 'general',
        public int $status = 422,
    ) {}

    public static function on(string $field, string $message): self
    {
        return new self($message, $field);
    }

    /**
     * Something else runs right now: asking again later may succeed.
     */
    public static function conflict(string $message): self
    {
        return new self($message, 'general', 409);
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function violation(): DomainRuleViolation
    {
        return new DomainRuleViolation($this->message, $this->field, $this->status);
    }

    /**
     * @template T
     *
     * @param  T|self  $result
     * @return T
     *
     * @throws DomainRuleViolation
     */
    public static function orThrow(mixed $result): mixed
    {
        if ($result instanceof self) {
            throw $result->violation();
        }

        return $result;
    }
}

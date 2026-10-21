<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A use case refused an operation for a reason the user can act on. The
 * message is user-facing (docs/10 §2.2 rule 10); bootstrap/app.php renders it
 * as a validation error on `$field`.
 */
final class DomainRuleViolation extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $field = 'general',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function on(string $field, string $message): self
    {
        return new self($message, $field);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 'general', 403);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 'general', 409);
    }
}

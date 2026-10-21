<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Variables\ReferenceError;
use DomainException;

/**
 * Broken references block a deploy with a clear error, never a silent literal
 * (docs/06 §3.1). The message lists every offending reference (names only).
 */
final class UnresolvedReferences extends DomainException
{
    /**
     * @param  list<ReferenceError>  $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        $prefix = array_all($errors, static fn (ReferenceError $error): bool => $error->reason === ReferenceError::SECRET_IN_PUBLIC_VALUE)
            ? 'Secrets in public variables: '
            : 'Unresolved variable references: ';

        parent::__construct($prefix.implode('; ', array_map(static fn (ReferenceError $error): string => $error->message(), $errors)));
    }

    public function toDomainRuleViolation(): DomainRuleViolation
    {
        return DomainRuleViolation::on('variables', $this->getMessage());
    }
}

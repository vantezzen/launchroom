<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiAbility;
use RuntimeException;

/**
 * The API token lacks the ability a route requires (docs/16 §1.2): 403
 * `missing_ability`, before the controller runs.
 */
final class MissingApiAbility extends RuntimeException
{
    public function __construct(public readonly ApiAbility $ability)
    {
        parent::__construct("This token can't do that: it needs the \"{$ability->value}\" ability.");
    }
}

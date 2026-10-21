<?php

declare(strict_types=1);

namespace App\Support\Templates;

use RuntimeException;

/**
 * A template file that doesn't follow `launchroom.template/v1`, or inputs
 * that don't fit it.
 */
final class TemplateInvalid extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly array $problems, public readonly ?string $field = null)
    {
        parent::__construct(implode(' ', $problems));
    }
}

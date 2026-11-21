<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * A broken reference in a variable's value and the valid references it
 * most likely meant (docs/06 §3.4), closest first. Suggestions only name
 * scopes and keys of this environment, never values.
 */
final class ReferenceFixData extends Data
{
    /**
     * @param  list<string>  $suggestions  whole expressions, e.g. `${{web.PUBLIC_URL}}`
     */
    public function __construct(
        public string $expression,
        public array $suggestions,
    ) {}
}

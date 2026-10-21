<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * What `${{` can complete to in this environment: a scope (a service slug,
 * "shared", or null for the service itself) and its variable names.
 */
final class ReferenceScopeData extends Data
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public ?string $scope,
        public string $label,
        public array $keys,
    ) {}
}

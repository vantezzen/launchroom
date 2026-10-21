<?php

declare(strict_types=1);

namespace App\Data\Variables;

use Spatie\LaravelData\Data;

/**
 * The variables tab of a service in an environment (docs/06 §3.4).
 */
final class ServiceVariablesData extends Data
{
    /**
     * @param  list<VariableRowData>  $variables
     * @param  list<SharedVariableRowData>  $shared
     * @param  list<SystemVariableData>  $system
     * @param  list<ReferenceScopeData>  $scopes
     * @param  list<EnvironmentOptionData>  $environments
     */
    public function __construct(
        public string $serviceSlug,
        public string $environmentId,
        public array $variables,
        public array $shared,
        public array $system,
        public array $scopes,
        public array $environments,
        public bool $canWrite,
        public bool $canRevealSecrets,
        public string $secretPlaceholder,
    ) {}
}

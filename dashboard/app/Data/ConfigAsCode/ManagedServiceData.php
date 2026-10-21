<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * What launchroom.json manages for one service in one environment
 * (docs/18 §3.3): settings paths (`build.buildCommand`, `deploy.healthcheck.path`,
 * `scaling.replicas`, `name`, `enabled`, …), variable keys, custom domains
 * and the line of the service in the file (for "Edit in launchroom.json").
 */
final class ManagedServiceData extends Data
{
    /**
     * @param  list<string>  $fields
     * @param  list<string>  $variables
     * @param  list<string>  $domains
     */
    public function __construct(
        public string $slug,
        public int $line = 1,
        public array $fields = [],
        public array $variables = [],
        public array $domains = [],
    ) {}

    public function manages(string $field): bool
    {
        return array_any($this->fields, static fn (string $managed): bool => $managed === $field || str_starts_with($managed, $field.'.') || str_starts_with($field, $managed.'.'));
    }
}

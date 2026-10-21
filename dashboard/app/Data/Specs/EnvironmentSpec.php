<?php

declare(strict_types=1);

namespace App\Data\Specs;

use Spatie\LaravelData\Data;

/**
 * The immutable snapshot of an environment's intent stored with every
 * revision (docs/06 §4). Staged changes are SpecDiff(lastApplied, current).
 */
final class EnvironmentSpec extends Data
{
    /**
     * @param  array<string, ServiceSpec>  $services  keyed by service public id
     * @param  array<string, ConnectionSpec>  $connections  keyed by connection public id
     * @param  array<string, VariableSpec>  $sharedVariables  keyed by variable public id
     * @param  array<string, string>  $sharedServices  previews: service public id → base environment public id whose instance is shared ("share base instance", docs/06 §5)
     */
    public function __construct(
        public EnvironmentMetaSpec $environment,
        public array $services = [],
        public array $connections = [],
        public array $sharedVariables = [],
        public array $sharedServices = [],
    ) {}

    public function service(string $id): ?ServiceSpec
    {
        return $this->services[$id] ?? null;
    }

    public function serviceBySlug(string $slug): ?ServiceSpec
    {
        foreach ($this->services as $service) {
            if ($service->slug === $slug) {
                return $service;
            }
        }

        return null;
    }
}

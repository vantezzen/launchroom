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

    /**
     * The connection variables a worker or cron takes from its app: they run
     * the app's code, so they get what the app's connections inject. A key
     * the process's own connections also inject is left to those.
     *
     * @return array<string, VariableSpec> keyed by variable public id
     */
    public function inheritedConnectionVariables(ServiceSpec $service): array
    {
        $parent = $service->kind->requiresParent() && $service->parentId !== null ? $this->service($service->parentId) : null;

        if (! $parent instanceof ServiceSpec) {
            return [];
        }

        $own = array_flip(array_map(static fn (VariableSpec $variable): string => $variable->key, $service->connectionVariables));

        return array_filter($parent->connectionVariables, static fn (VariableSpec $variable): bool => ! isset($own[$variable->key]));
    }

    /**
     * Inherited connection variables first, then the service's own.
     *
     * @return array<string, VariableSpec> keyed by variable public id
     */
    public function connectionVariablesOf(ServiceSpec $service): array
    {
        return [...$this->inheritedConnectionVariables($service), ...$service->connectionVariables];
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

<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Enums\VariableLayer;
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
        $parent = $this->parentOf($service);

        if (! $parent instanceof ServiceSpec) {
            return [];
        }

        $own = array_flip(array_map(static fn (VariableSpec $variable): string => $variable->key, $service->connectionVariables));

        return array_filter($parent->connectionVariables, static fn (VariableSpec $variable): bool => ! isset($own[$variable->key]));
    }

    /**
     * The app a worker or cron runs the code of, or null.
     */
    public function parentOf(ServiceSpec $service): ?ServiceSpec
    {
        return $service->kind->requiresParent() && $service->parentId !== null ? $this->service($service->parentId) : null;
    }

    /**
     * The app's own variables a worker or cron takes (docs/06 §3.2): per key
     * the row whose value applies to the app (a branch override over the
     * environment's), when it is available at runtime. The process runs the
     * app's image, so build-only rows don't apply. Keys the process sets
     * itself are included; precedence leaves them to the process.
     *
     * @return array<string, VariableSpec> keyed by variable public id
     */
    public function parentVariables(ServiceSpec $service): array
    {
        $parent = $this->parentOf($service);

        if (! $parent instanceof ServiceSpec) {
            return [];
        }

        $winners = [];

        foreach (VariableLayer::cases() as $layer) {
            foreach ($parent->variables as $variable) {
                if ($variable->layer === $layer) {
                    $winners[$variable->key] = $variable;
                }
            }
        }

        $inherited = [];

        foreach ($winners as $variable) {
            if ($variable->exposure->includesRuntime()) {
                $inherited[$variable->id] = $variable;
            }
        }

        return $inherited;
    }

    /**
     * The app's variables that reach a worker or cron: parentVariables()
     * without the keys the process sets itself (its own variables and what
     * its own connections inject win).
     *
     * @return array<string, VariableSpec> keyed by variable public id
     */
    public function inheritedVariables(ServiceSpec $service): array
    {
        $inherited = $this->parentVariables($service);

        if ($inherited === []) {
            return [];
        }

        $own = [];

        foreach ([...$service->variables, ...$service->connectionVariables] as $variable) {
            $own[$variable->key] = true;
        }

        return array_filter($inherited, static fn (VariableSpec $variable): bool => ! isset($own[$variable->key]));
    }

    /**
     * Whether $variable reaches $service as one of its app's variables
     * (inheritedVariables()): its value resolves in the app's scope.
     */
    public function isInheritedVariable(ServiceSpec $service, VariableSpec $variable): bool
    {
        return isset($this->parentOf($service)?->variables[$variable->id]);
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

    /**
     * The variables $service gets, lowest precedence first (docs/06 §3.1):
     * opted-in shared < connection-injected (the app's) < the app's own
     * variables (workers and crons, isInheritedVariable()) < connection-
     * injected (its own) < environment-targeted < branch override. System
     * variables sit below all of them and aren't variable rows. Folding by
     * key gives the winner.
     *
     * @return list<VariableSpec>
     */
    public function variablesInPrecedence(ServiceSpec $service): array
    {
        $ordered = [];

        foreach ($service->sharedVariableIds as $variableId) {
            if (isset($this->sharedVariables[$variableId])) {
                $ordered[] = $this->sharedVariables[$variableId];
            }
        }

        foreach ($this->inheritedConnectionVariables($service) as $variable) {
            $ordered[] = $variable;
        }

        foreach ($this->inheritedVariables($service) as $variable) {
            $ordered[] = $variable;
        }

        foreach ($service->connectionVariables as $variable) {
            $ordered[] = $variable;
        }

        foreach (VariableLayer::cases() as $layer) {
            foreach ($service->variables as $variable) {
                if ($variable->layer === $layer) {
                    $ordered[] = $variable;
                }
            }
        }

        return $ordered;
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

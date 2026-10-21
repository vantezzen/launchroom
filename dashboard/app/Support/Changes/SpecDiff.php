<?php

declare(strict_types=1);

namespace App\Support\Changes;

use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\ConnectionSpec;
use App\Data\Specs\DomainSpec;
use App\Data\Specs\EnvironmentMetaSpec;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\StagedChanges;
use App\Data\Specs\VariableSpec;
use App\Data\Specs\VolumeSpec;
use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Support\CanonicalJson;
use Spatie\LaravelData\Data;

/**
 * Staged changes = diff(last applied revision, current intent) (docs/06 §4).
 *
 * Pure: two EnvironmentSpecs in, typed change items out. Each item carries the
 * effect it needs when deployed, read from #[ChangeAffects] on the Data
 * property that changed (docs/04 §7 build-affecting vs runtime-only), and its
 * promotion class (docs/06 §6.2). Per service, the strongest effect wins, and
 * restarts propagate to services whose variables reference a changed service
 * (`${{postgres.DATABASE_URL}}` after a password rotation) or a changed shared
 * variable.
 */
final class SpecDiff
{
    /** @var list<ChangeItem> */
    private array $items = [];

    /** @var array<string, ChangeEffect> */
    private array $effects = [];

    /** @var array<string, ServiceKind> service public id => kind */
    private array $kinds = [];

    /** @var array<string, true> ids of shared variables that changed */
    private array $changedShared = [];

    /** @var array<string, true> keys of shared variables that changed */
    private array $changedSharedKeys = [];

    private function __construct() {}

    public static function between(?EnvironmentSpec $base, EnvironmentSpec $current): StagedChanges
    {
        $diff = new self;

        foreach ([...($base->services ?? []), ...$current->services] as $id => $service) {
            $diff->kinds[$id] = $service->kind;
        }

        $diff->diffEnvironment($base?->environment, $current->environment);
        $diff->diffServices($base->services ?? [], $current->services);
        $diff->diffConnections($base->connections ?? [], $current->connections, $current);
        $diff->diffSharedVariables($base->sharedVariables ?? [], $current->sharedVariables);
        $diff->propagate($current);

        return new StagedChanges($diff->items, $diff->effects);
    }

    private function diffEnvironment(?EnvironmentMetaSpec $before, EnvironmentMetaSpec $after): void
    {
        if ($before instanceof EnvironmentMetaSpec) {
            $this->diffFields(EnvironmentMetaSpec::class, $before, $after, 'environment', null, 'environment ');
        }
    }

    /**
     * @param  array<string, ServiceSpec>  $before
     * @param  array<string, ServiceSpec>  $after
     */
    private function diffServices(array $before, array $after): void
    {
        foreach ($after as $id => $service) {
            $old = $before[$id] ?? null;
            $path = "services.{$id}";

            if ($old === null) {
                $this->add($path, ChangeKind::Added, $service->enabled ? ChangeEffect::New : ChangeEffect::None, PromotionClass::Structural, "{$service->slug}: new {$service->kind->value} service", $id);

                continue;
            }

            if ($old->enabled !== $service->enabled) {
                $this->add("{$path}.enabled", ChangeKind::Changed, $service->enabled ? ChangeEffect::New : ChangeEffect::Remove, PromotionClass::EnvironmentSpecific, "{$service->slug}: ".($service->enabled ? 'enabled' : 'disabled'), $id, $old->enabled ? 'true' : 'false', $service->enabled ? 'true' : 'false');

                if (! $service->enabled) {
                    continue;
                }
            }

            $this->diffService($old, $service);
        }

        foreach ($before as $id => $service) {
            if (! isset($after[$id])) {
                $this->add("services.{$id}", ChangeKind::Removed, $service->enabled ? ChangeEffect::Remove : ChangeEffect::None, PromotionClass::Structural, "{$service->slug}: removed", $id);
            }
        }
    }

    private function diffService(ServiceSpec $before, ServiceSpec $after): void
    {
        $id = $after->id;
        $path = "services.{$id}";
        $prefix = "{$after->slug}: ";

        $this->diffFields(ServiceSpec::class, $before, $after, $path, $id, $prefix);
        $this->diffFields(BuildSettings::class, $before->build, $after->build, "{$path}.build", $id, $prefix.'build ');
        $this->diffFields(DeploySettings::class, $before->deploy, $after->deploy, "{$path}.deploy", $id, $prefix);
        $this->diffFields(ScalingSettings::class, $before->scaling, $after->scaling, "{$path}.scaling", $id, $prefix);

        $this->diffVariables($before->variables, $after->variables, "{$path}.variables", $id, $prefix);
        $this->diffVariables($before->connectionVariables, $after->connectionVariables, "{$path}.connectionVariables", $id, $prefix.'connection ');

        foreach (array_diff($after->sharedVariableIds, $before->sharedVariableIds) as $variableId) {
            $this->add("{$path}.sharedVariables.{$variableId}", ChangeKind::Added, ChangeEffect::Restart, PromotionClass::Structural, $prefix.'uses a shared variable', $id);
        }

        foreach (array_diff($before->sharedVariableIds, $after->sharedVariableIds) as $variableId) {
            $this->add("{$path}.sharedVariables.{$variableId}", ChangeKind::Removed, ChangeEffect::Restart, PromotionClass::Structural, $prefix.'no longer uses a shared variable', $id);
        }

        $this->diffKeyed($before->domains, $after->domains, "{$path}.domains", $id, ChangeEffect::Route, PromotionClass::EnvironmentSpecific, static fn (DomainSpec $domain): string => $prefix.'domain '.$domain->hostname);
        $this->diffKeyed($before->volumes, $after->volumes, "{$path}.volumes", $id, ChangeEffect::Restart, PromotionClass::Structural, static fn (VolumeSpec $volume): string => $prefix.'volume '.$volume->mountPath);
    }

    /**
     * Compares every #[ChangeAffects] property of two Data objects.
     *
     * @param  class-string<Data>  $class
     */
    private function diffFields(string $class, Data $before, Data $after, string $path, ?string $serviceId, string $labelPrefix): void
    {
        $old = $before->toArray();
        $new = $after->toArray();

        foreach (FieldMeta::of($class) as $field => [$effect, $promotion]) {
            $oldValue = CanonicalJson::encode($old[$field] ?? null);
            $newValue = CanonicalJson::encode($new[$field] ?? null);

            if ($oldValue !== $newValue) {
                $this->add("{$path}.{$field}", ChangeKind::Changed, $effect, $promotion, $labelPrefix.$field, $serviceId, $oldValue, $newValue);
            }
        }
    }

    /**
     * @param  array<string, VariableSpec>  $before
     * @param  array<string, VariableSpec>  $after
     */
    private function diffVariables(array $before, array $after, string $path, ?string $serviceId, string $labelPrefix): void
    {
        foreach ($after as $id => $variable) {
            $old = $before[$id] ?? null;

            if ($old === null) {
                $this->add("{$path}.{$id}", ChangeKind::Added, $this->variableEffect($variable), PromotionClass::Structural, "{$labelPrefix}variable {$variable->key} added", $serviceId);

                continue;
            }

            if ($this->variableFingerprint($old) !== $this->variableFingerprint($variable)) {
                // Only the value changed: secret values are never promoted (docs/06 §6.2).
                $valueOnly = $old->key === $variable->key && $old->type === $variable->type && $old->exposure === $variable->exposure;
                $promotion = $valueOnly && ($variable->type === VariableType::Secret || $variable->generated) ? PromotionClass::Never : PromotionClass::Structural;
                $effect = $this->variableEffect($old)->max($this->variableEffect($variable));

                $this->add("{$path}.{$id}", ChangeKind::Changed, $effect, $promotion, "{$labelPrefix}variable {$variable->key} changed", $serviceId);
            }
        }

        foreach ($before as $id => $variable) {
            if (! isset($after[$id])) {
                $this->add("{$path}.{$id}", ChangeKind::Removed, $this->variableEffect($variable), PromotionClass::Structural, "{$labelPrefix}variable {$variable->key} removed", $serviceId);
            }
        }
    }

    /**
     * @template T of Data
     *
     * @param  array<string, T>  $before
     * @param  array<string, T>  $after
     * @param  \Closure(T): string  $label
     */
    private function diffKeyed(array $before, array $after, string $path, ?string $serviceId, ChangeEffect $effect, PromotionClass $promotion, \Closure $label): void
    {
        foreach ($after as $key => $item) {
            $old = $before[$key] ?? null;

            if ($old === null) {
                $this->add("{$path}.{$key}", ChangeKind::Added, $effect, $promotion, $label($item).' added', $serviceId);
            } elseif (CanonicalJson::encode($old->toArray()) !== CanonicalJson::encode($item->toArray())) {
                $this->add("{$path}.{$key}", ChangeKind::Changed, $effect, $promotion, $label($item).' changed', $serviceId);
            }
        }

        foreach ($before as $key => $item) {
            if (! isset($after[$key])) {
                $this->add("{$path}.{$key}", ChangeKind::Removed, $effect, $promotion, $label($item).' removed', $serviceId);
            }
        }
    }

    /**
     * @param  array<string, ConnectionSpec>  $before
     * @param  array<string, ConnectionSpec>  $after
     */
    private function diffConnections(array $before, array $after, EnvironmentSpec $current): void
    {
        $slug = static fn (string $serviceId): string => $current->service($serviceId)->slug ?? $serviceId;

        foreach ($after as $id => $connection) {
            $old = $before[$id] ?? null;
            $label = $slug($connection->fromServiceId).' → '.$slug($connection->toServiceId);

            if ($old === null) {
                $this->add("connections.{$id}", ChangeKind::Added, ChangeEffect::Restart, PromotionClass::Structural, "connection {$label} added", $connection->fromServiceId);
            } elseif (CanonicalJson::encode($old->toArray()) !== CanonicalJson::encode($connection->toArray())) {
                $this->add("connections.{$id}", ChangeKind::Changed, ChangeEffect::Restart, PromotionClass::Structural, "connection {$label} changed", $connection->fromServiceId);
            }
        }

        foreach ($before as $id => $connection) {
            if (! isset($after[$id])) {
                $this->add("connections.{$id}", ChangeKind::Removed, ChangeEffect::Restart, PromotionClass::Structural, 'connection '.$slug($connection->fromServiceId).' → '.$slug($connection->toServiceId).' removed', $connection->fromServiceId);
            }
        }
    }

    /**
     * Shared variables affect only the services that use them; that is
     * resolved in propagate().
     *
     * @param  array<string, VariableSpec>  $before
     * @param  array<string, VariableSpec>  $after
     */
    private function diffSharedVariables(array $before, array $after): void
    {
        $count = count($this->items);
        $this->diffVariables($before, $after, 'sharedVariables', null, 'shared ');

        foreach (array_slice($this->items, $count) as $item) {
            $id = substr($item->path, strlen('sharedVariables.'));
            $this->changedShared[$id] = true;
            $key = ($after[$id] ?? $before[$id] ?? null)?->key;

            if ($key !== null) {
                $this->changedSharedKeys[$key] = true;
            }

            // Renamed keys: references to the old name are affected too.
            if (isset($before[$id])) {
                $this->changedSharedKeys[$before[$id]->key] = true;
            }
        }
    }

    /**
     * Services referencing a changed service or shared variable restart
     * (or rebuild, when the referencing variable is build-scoped), to a
     * fixpoint: A → B → C chains propagate.
     */
    private function propagate(EnvironmentSpec $current): void
    {
        $changedSlugs = [];

        foreach ($this->effects as $serviceId => $effect) {
            $service = $current->service($serviceId);

            if ($service instanceof ServiceSpec && $effect !== ChangeEffect::None) {
                $changedSlugs[$service->slug] = true;
            }
        }

        do {
            $grew = false;

            foreach ($current->services as $id => $service) {
                if (! $service->enabled) {
                    continue;
                }

                $needed = $this->propagatedEffect($service, $changedSlugs);

                if (! $needed instanceof ChangeEffect) {
                    continue;
                }

                $existing = $this->effects[$id] ?? ChangeEffect::None;
                $merged = $existing->max($needed->forKind($service->kind));

                if ($merged !== $existing) {
                    $this->effects[$id] = $merged;
                }

                if (! isset($changedSlugs[$service->slug])) {
                    $changedSlugs[$service->slug] = true;
                    $grew = true;
                }
            }
        } while ($grew);
    }

    /**
     * @param  array<string, true>  $changedSlugs
     */
    private function propagatedEffect(ServiceSpec $service, array $changedSlugs): ?ChangeEffect
    {
        $needed = null;

        if (array_intersect_key(array_flip($service->sharedVariableIds), $this->changedShared) !== []) {
            $needed = ChangeEffect::Restart;
        }

        foreach ([...$service->variables, ...$service->connectionVariables] as $variable) {
            foreach ($variable->references as $reference) {
                $scope = str_contains($reference, '.') ? strstr($reference, '.', true) : null;
                $key = str_contains($reference, '.') ? substr($reference, strpos($reference, '.') + 1) : $reference;

                $hit = match (true) {
                    $scope === 'shared' => isset($this->changedSharedKeys[$key]),
                    $scope !== null && $scope !== $service->slug => isset($changedSlugs[$scope]),
                    default => false,
                };

                if ($hit) {
                    $effect = $variable->exposure->includesBuild() ? ChangeEffect::Rebuild : ChangeEffect::Restart;
                    $needed = $needed?->max($effect) ?? $effect;
                }
            }
        }

        return $needed;
    }

    private function variableEffect(VariableSpec $variable): ChangeEffect
    {
        return $variable->exposure->includesBuild() ? ChangeEffect::Rebuild : ChangeEffect::Restart;
    }

    private function variableFingerprint(VariableSpec $variable): string
    {
        return CanonicalJson::encode([$variable->key, $variable->type->value, $variable->version, $variable->exposure->value, $variable->layer]);
    }

    private function add(string $path, ChangeKind $kind, ChangeEffect $effect, PromotionClass $promotion, string $label, ?string $serviceId, ?string $before = null, ?string $after = null): void
    {
        if ($serviceId !== null && isset($this->kinds[$serviceId])) {
            $effect = $effect->forKind($this->kinds[$serviceId]);
        }

        $this->items[] = new ChangeItem($path, $kind, $effect, $promotion, $label, $serviceId, $before, $after);

        if ($serviceId !== null && ! str_starts_with($path, 'sharedVariables.')) {
            $this->effects[$serviceId] = ($this->effects[$serviceId] ?? ChangeEffect::None)->max($effect);
        }
    }
}

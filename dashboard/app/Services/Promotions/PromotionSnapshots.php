<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Data\Promotions\PromotionEntry;
use App\Data\Promotions\PromotionSnapshot;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\ChangeEffect;
use App\Enums\PromotionClass;
use App\Enums\PromotionEntryType;
use App\Enums\ServiceKind;
use App\Enums\VariableType;
use App\Models\Project;
use App\Models\Variable;
use App\Services\Crypto\ProjectKeyring;
use App\Support\CanonicalJson;
use App\Support\Changes\FieldMeta;
use Illuminate\Support\Str;

/**
 * Turns an EnvironmentSpec into its promotable form (docs/06 §6): entries
 * keyed by logical paths that mean the same thing in every environment.
 *
 * - `services.<svc>` and its instance settings `services.<svc>.(enabled|branchOverride|build.*|deploy.*|scaling.*)`
 * - `services.<svc>.variables.<KEY>`: environment-layer rows by key (each
 *   environment has its own rows, so ids don't match across environments);
 *   the value enters the fingerprint as a keyed MAC, generated values not at all
 * - `services.<svc>.sharedVariables.<KEY>` (opt-ins), `services.<svc>.volumes.<mount path>`
 * - `connections.<con>`, `sharedVariables.<KEY>`, `environment.triggers`
 *
 * Left out: service identity (name, icon, source: shared by all environments),
 * domains (hostnames are unique per install), connection-owned variables
 * (they follow the connection) and preview branch overrides.
 */
final readonly class PromotionSnapshots
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    public function of(Project $project, EnvironmentSpec $spec): PromotionSnapshot
    {
        $generators = $this->generators($project, $spec);
        $entries = [];

        foreach ($spec->services as $id => $service) {
            $path = "services.{$id}";
            $entries[$path] = new PromotionEntry(
                type: PromotionEntryType::Service,
                fingerprint: $service->kind->value,
                label: "{$service->kind->value} service",
                promotion: PromotionClass::Structural,
                effect: ChangeEffect::New,
                serviceId: $id,
                display: $service->name,
                holdsData: $service->kind->isStateful() || $service->volumes !== [],
            );

            $this->fields($entries, $service);

            foreach ($service->variables as $variable) {
                if ($variable->layer !== VariableSpec::LAYER_ENVIRONMENT) {
                    continue;
                }

                $entries["{$path}.variables.{$variable->key}"] = $this->variable($project, $variable, PromotionEntryType::Variable, $service, $generators[$variable->id] ?? null);
            }

            foreach ($service->sharedVariableIds as $sharedId) {
                $shared = $spec->sharedVariables[$sharedId] ?? null;

                if ($shared !== null) {
                    $entries["{$path}.sharedVariables.{$shared->key}"] = new PromotionEntry(
                        type: PromotionEntryType::SharedUsage,
                        fingerprint: 'used',
                        label: "uses shared {$shared->key}",
                        promotion: PromotionClass::Structural,
                        effect: ChangeEffect::Restart,
                        serviceId: $id,
                        key: $shared->key,
                    );
                }
            }

            foreach ($service->volumes as $volume) {
                $entries["{$path}.volumes.{$volume->mountPath}"] = new PromotionEntry(
                    type: PromotionEntryType::Volume,
                    fingerprint: 'mounted',
                    label: "volume {$volume->mountPath}",
                    promotion: PromotionClass::Structural,
                    effect: ChangeEffect::Restart,
                    serviceId: $id,
                    key: $volume->mountPath,
                );
            }
        }

        foreach ($spec->connections as $id => $connection) {
            $from = $spec->service($connection->fromServiceId);
            $to = $spec->service($connection->toServiceId);
            $entries["connections.{$id}"] = new PromotionEntry(
                type: PromotionEntryType::Connection,
                fingerprint: 'connected',
                label: 'connection '.($from->slug ?? '?').' → '.($to->slug ?? '?'),
                promotion: PromotionClass::Structural,
                effect: ChangeEffect::Restart,
                serviceId: $connection->fromServiceId,
                key: $id,
                dependsOn: ["services.{$connection->fromServiceId}", "services.{$connection->toServiceId}"],
            );
        }

        foreach ($spec->sharedVariables as $variable) {
            if ($variable->layer === VariableSpec::LAYER_ENVIRONMENT) {
                $entries["sharedVariables.{$variable->key}"] = $this->variable($project, $variable, PromotionEntryType::SharedVariable, null, $generators[$variable->id] ?? null);
            }
        }

        $triggers = CanonicalJson::encode($spec->environment->triggers->toArray());
        $entries['environment.triggers'] = new PromotionEntry(
            type: PromotionEntryType::Triggers,
            fingerprint: $triggers,
            label: 'deploy triggers',
            promotion: PromotionClass::EnvironmentSpecific,
            effect: ChangeEffect::None,
            display: $triggers,
        );

        ksort($entries);

        return new PromotionSnapshot($entries);
    }

    /**
     * @param  array<string, PromotionEntry>  $entries
     */
    private function fields(array &$entries, ServiceSpec $service): void
    {
        $path = "services.{$service->id}";
        $simple = [
            'enabled' => [$service->enabled, $service->enabled ? ChangeEffect::New : ChangeEffect::Remove],
            'branchOverride' => [$service->branchOverride, ChangeEffect::Rebuild],
        ];

        foreach ($simple as $field => [$value, $effect]) {
            $encoded = CanonicalJson::encode($value);
            $entries["{$path}.{$field}"] = new PromotionEntry(
                type: PromotionEntryType::Field,
                fingerprint: $encoded,
                label: $field === 'enabled' ? 'enabled' : 'branch override',
                promotion: PromotionClass::EnvironmentSpecific,
                effect: $effect->forKind($service->kind),
                serviceId: $service->id,
                key: $field,
                display: $encoded,
            );
        }

        foreach (['build' => BuildSettings::class, 'deploy' => DeploySettings::class, 'scaling' => ScalingSettings::class] as $group => $class) {
            $values = $service->{$group}->toArray();

            foreach (FieldMeta::of($class) as $field => [$effect, $promotion]) {
                $encoded = CanonicalJson::encode($values[$field] ?? null);
                $words = Str::lower(Str::headline($field));
                $entries["{$path}.{$group}.{$field}"] = new PromotionEntry(
                    type: PromotionEntryType::Field,
                    fingerprint: $encoded,
                    label: $group === 'build' ? "build {$words}" : $words,
                    promotion: $promotion,
                    effect: $effect->forKind($service->kind),
                    serviceId: $service->id,
                    key: "{$group}.{$field}",
                    display: $encoded,
                );
            }
        }
    }

    private function variable(Project $project, VariableSpec $variable, PromotionEntryType $type, ?ServiceSpec $service, ?string $generator): PromotionEntry
    {
        $secret = $variable->type === VariableType::Secret;
        $plaintext = $variable->generated ? null : $this->keyring->decryptVariable($project, $variable->id, $variable->version, $variable->ciphertext);
        $valueFingerprint = $plaintext === null ? 'generated' : $this->keyring->mac($project, 'promotion-value', $plaintext);
        $effect = $variable->exposure->includesBuild() ? ChangeEffect::Rebuild : ChangeEffect::Restart;

        return new PromotionEntry(
            type: $type,
            fingerprint: hash('sha256', CanonicalJson::encode([$variable->type->value, $variable->exposure->value, $valueFingerprint])),
            label: ($type === PromotionEntryType::SharedVariable ? 'shared variable ' : 'variable ').$variable->key,
            promotion: $secret ? PromotionClass::Never : PromotionClass::Structural,
            effect: $service instanceof ServiceSpec ? $effect->forKind($service->kind) : $effect->forKind(ServiceKind::App),
            serviceId: $service?->id,
            key: $variable->key,
            display: $secret || $plaintext === null ? null : mb_strimwidth($plaintext, 0, 200, '…'),
            secret: $secret,
            generated: $variable->generated,
            generator: $generator,
            valueFingerprint: $valueFingerprint,
        );
    }

    /**
     * @return array<string, string> variable public id => generator
     */
    private function generators(Project $project, EnvironmentSpec $spec): array
    {
        $ids = [];

        foreach ($spec->services as $service) {
            foreach ($service->variables as $variable) {
                if ($variable->generated) {
                    $ids[] = $variable->id;
                }
            }
        }

        foreach ($spec->sharedVariables as $variable) {
            if ($variable->generated) {
                $ids[] = $variable->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $generators */
        $generators = Variable::query()->where('project_id', $project->id)->whereIn('public_id', $ids)->whereNotNull('generator')->pluck('generator', 'public_id')->all();

        return $generators;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Blueprints;

use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintDatabase;
use App\Data\Blueprints\BlueprintScaling;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Forms\NumberRangeData;
use App\Data\Services\HealthcheckSettings;
use App\Data\Services\ScalingSettings;
use App\Enums\ServiceKind;
use App\Enums\WorkloadCapability;
use App\Support\Connections\ProvidedVariables;
use App\Support\Hostname;
use App\Support\Images\ImageReference;
use App\Support\Services\ConfigFileRules;
use App\Support\Services\VolumeMountRules;
use App\Support\Slug;
use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableKey;
use App\Support\Variables\VariableReferences;

/**
 * The rules every ProjectBlueprint passes before it is applied (docs/17
 * §1). Pure; the Actions it feeds check again (slugs, volumes, variables),
 * so this is the early, complete answer rather than the only guard.
 */
final class BlueprintValidator
{
    public const int MAX_SERVICES = 50;

    /**
     * @return list<string> one sentence per problem; empty when valid
     */
    public static function errors(ProjectBlueprint $blueprint): array
    {
        $errors = [];

        if ($blueprint->version !== ProjectBlueprint::VERSION) {
            $errors[] = "Unknown blueprint version {$blueprint->version}.";
        }

        if (trim($blueprint->name) === '' || mb_strlen($blueprint->name) > 100) {
            $errors[] = 'The name must be 1 to 100 characters.';
        }

        if ($blueprint->services === [] || count($blueprint->services) > self::MAX_SERVICES) {
            $errors[] = 'A blueprint has 1 to '.self::MAX_SERVICES.' services.';
        }

        /** @var array<string, BlueprintService> $byKey */
        $byKey = [];

        foreach ($blueprint->services as $service) {
            if (! Slug::isValid($service->key) || in_array($service->key, Slug::RESERVED, true)) {
                $errors[] = "“{$service->key}” isn’t a valid service key.";
            }

            if (isset($byKey[$service->key])) {
                $errors[] = "The key {$service->key} is used twice.";
            }

            $byKey[$service->key] = $service;
        }

        $shared = [];

        foreach ($blueprint->sharedVariables as $variable) {
            $errors = [...$errors, ...self::variable($variable, 'shared')];

            if (isset($shared[$variable->key])) {
                $errors[] = "The shared variable {$variable->key} is defined twice.";
            }

            $shared[$variable->key] = true;
        }

        foreach ($blueprint->services as $service) {
            $errors = [...$errors, ...self::service($service, $byKey, $shared)];
        }

        return array_values(array_unique($errors));
    }

    public static function isValid(ProjectBlueprint $blueprint): bool
    {
        return self::errors($blueprint) === [];
    }

    /**
     * @param  array<string, BlueprintService>  $byKey
     * @param  array<string, true>  $shared
     * @return list<string>
     */
    private static function service(BlueprintService $service, array $byKey, array $shared): array
    {
        $errors = [];
        $key = $service->key;
        $web = $service->kind->isWeb();

        if (trim($service->name) === '' || mb_strlen($service->name) > 63) {
            $errors[] = "{$key}: the name must be 1 to 63 characters.";
        }

        $sources = array_filter([$service->image !== null, $service->build instanceof BlueprintBuild, $service->database instanceof BlueprintDatabase]);

        $kindOk = match ($service->kind) {
            ServiceKind::Image => $service->image !== null && count($sources) === 1 && ImageReference::tryParse($service->image) instanceof ImageReference,
            ServiceKind::App => $service->build instanceof BlueprintBuild && count($sources) === 1,
            ServiceKind::Database => $service->database instanceof BlueprintDatabase && count($sources) === 1,
            ServiceKind::Worker => $sources === [] && $service->parent !== null && ($byKey[$service->parent] ?? null)?->kind === ServiceKind::App && $service->startCommand !== null,
            ServiceKind::Cron => false,
        };

        if (! $kindOk) {
            $errors[] = "{$key}: a {$service->kind->value} service needs ".match ($service->kind) {
                ServiceKind::Image => 'a valid image and nothing else',
                ServiceKind::App => 'a build and nothing else',
                ServiceKind::Database => 'a database engine and nothing else',
                ServiceKind::Worker => 'an app as its parent and a start command',
                ServiceKind::Cron => 'a schedule (crons aren’t supported in blueprints yet)',
            }.'.';
        }

        if ($service->port !== null && (! $web || $service->port < 1 || $service->port > 65535)) {
            $errors[] = "{$key}: only app and image services have a port (1–65535).";
        }

        if ($service->public && ! $web) {
            $errors[] = "{$key}: only app and image services can be public.";
        }

        if ($service->domain !== null && (! $web || ! Hostname::isValid($service->domain))) {
            $errors[] = "{$key}: “{$service->domain}” isn’t a valid domain for this service.";
        }

        foreach (['entrypoint' => $service->entrypoint, 'start command' => $service->startCommand] as $label => $argv) {
            if ($argv !== null && ($argv === [] || count($argv) > 256 || array_any($argv, static fn (string $word): bool => strlen($word) > 4096 || str_contains($word, "\0")))) {
                $errors[] = "{$key}: the {$label} must be argv of at most 256 words.";
            }
        }

        if ($service->kind === ServiceKind::Database && ($service->entrypoint !== null || $service->startCommand !== null || $service->volumes !== [] || $service->capabilities !== [] || $service->scaling instanceof BlueprintScaling)) {
            $errors[] = "{$key}: the engine driver manages a database’s command, volume and size.";
        }

        if (array_diff($service->capabilities, WorkloadCapability::values()) !== []) {
            $errors[] = "{$key}: capabilities must be among ".implode(', ', WorkloadCapability::values()).'.';
        }

        if ($service->volumes !== [] && ! $web) {
            $errors[] = "{$key}: only app and image services have volumes.";
        }

        foreach ($service->volumes as $index => $path) {
            if (! VolumeMountRules::isMountable($path)) {
                $errors[] = "{$key}: {$path} can’t be a mount path.";
            }

            foreach ($service->volumes as $other => $otherPath) {
                if ($other !== $index && ($path === $otherPath || str_starts_with($path, rtrim($otherPath, '/').'/'))) {
                    $errors[] = "{$key}: the volume at {$path} overlaps {$otherPath}.";
                }
            }
        }

        if ($service->files !== []) {
            if (! $web && $service->kind !== ServiceKind::Worker) {
                $errors[] = "{$key}: managed databases have no config files.";
            }

            foreach (ConfigFileRules::errors($service->files, $service->volumes) as $problem) {
                $errors[] = "{$key}: {$problem}";
            }
        }

        if ($service->provides !== []) {
            $provided = [];

            foreach ($service->provides as $variable) {
                $provided[$variable->key] = $variable->value;
            }

            if (! $web || count($provided) !== count($service->provides)) {
                $errors[] = "{$key}: only app and image services provide variables to connections, each key once.";
            }

            foreach (ProvidedVariables::errors($provided) as $problem) {
                $errors[] = "{$key}: {$problem}";
            }
        }

        if ($service->healthcheck instanceof HealthcheckSettings && ! $web) {
            $errors[] = "{$key}: only app and image services have a health check.";
        }

        if ($service->scaling instanceof BlueprintScaling) {
            foreach (['replicas' => 'replicas', 'cpus' => 'CPUs', 'memoryMb' => 'memory (MB)'] as $field => $label) {
                $value = $service->scaling->{$field};
                [$min, $max] = NumberRangeData::bounds(ScalingSettings::class, $field);

                if ($value !== null && ($value < $min || $value > $max)) {
                    $errors[] = "{$key}: {$label} must be between {$min} and {$max}.";
                }
            }
        }

        $seen = [];

        foreach ($service->variables as $variable) {
            $errors = [...$errors, ...self::variable($variable, $key)];

            if (isset($seen[$variable->key])) {
                $errors[] = "{$key}: the variable {$variable->key} is defined twice.";
            }

            $seen[$variable->key] = true;

            foreach (VariableReferences::parse($variable->value) as $reference) {
                if ($reference->scope === 'shared' ? ! isset($shared[$reference->key]) : ($reference->scope !== null && ! isset($byKey[$reference->scope]))) {
                    $errors[] = "{$key}: {$variable->key} references {$reference->name()}, which the blueprint doesn’t define.";
                }
            }
        }

        // jwt() is signed with a secret variable of the same service (D65).
        foreach ($service->variables as $variable) {
            $signingKey = GeneratedValues::signingKey(GeneratedValues::sole($variable->value));

            if ($signingKey === null && preg_match('/\$\{\{\s*jwt\(/', $variable->value) === 1) {
                $errors[] = "{$key}: {$variable->key} must be exactly one jwt() expression.";
            }

            if ($signingKey !== null && ! array_any($service->variables, static fn (BlueprintVariable $other): bool => $other->key === $signingKey && $other->secret && $other !== $variable)) {
                $errors[] = "{$key}: {$variable->key} is signed with {$signingKey}, which must be a secret variable of the same service.";
            }
        }

        $targets = [];

        foreach ($service->connections as $connection) {
            $target = $byKey[$connection->to] ?? null;

            if ($target === null || $target === $service) {
                $errors[] = "{$key}: it connects to {$connection->to}, which isn’t another service of the blueprint.";

                continue;
            }

            if ($target->kind !== ServiceKind::Database && ! $target->kind->isWeb()) {
                $errors[] = "{$key}: {$connection->to} has no address to connect to.";
            }

            if (isset($targets[$connection->to])) {
                $errors[] = "{$key}: it connects to {$connection->to} twice.";
            }

            $targets[$connection->to] = true;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function variable(BlueprintVariable $variable, string $owner): array
    {
        $errors = [];

        if (! VariableKey::isValid($variable->key)) {
            $errors[] = "{$owner}: “{$variable->key}” isn’t a valid variable name.";
        }

        if (strlen($variable->value) > VariableKey::MAX_VALUE_BYTES || str_contains($variable->value, "\0")) {
            $errors[] = "{$owner}: {$variable->key} is too long.";
        }

        if (VariableReferences::invalid($variable->value) !== []) {
            $errors[] = "{$owner}: {$variable->key} has an invalid \${{ }} expression.";
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Blueprints;

use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintDatabase;
use App\Data\Blueprints\BlueprintScaling;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Services\HealthcheckSettings;
use App\Enums\ServiceKind;
use App\Enums\WorkloadCapability;
use App\Support\Hostname;
use App\Support\Images\ImageReference;
use App\Support\Slug;
use App\Support\Variables\VariableReferences;

/**
 * The rules every ProjectBlueprint passes before it is applied (docs/17
 * §1). Pure; the Actions it feeds check again (slugs, volumes, variables),
 * so this is the early, complete answer rather than the only guard.
 */
final class BlueprintValidator
{
    public const int MAX_SERVICES = 50;

    /** launchroomd's capability allow-list (docs/03 §8). */
    /** launchroomd's capability allow-list ({@see WorkloadCapability}). */
    public const array CAPABILITIES = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'NET_BIND_SERVICE', 'SETGID', 'SETUID'];

    private const string KEY = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    private const string MOUNT_PATH = '/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D';

    private const array FORBIDDEN_MOUNTS = ['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'];

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
        $web = in_array($service->kind, [ServiceKind::App, ServiceKind::Image], true);

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

        if (array_diff($service->capabilities, self::CAPABILITIES) !== []) {
            $errors[] = "{$key}: capabilities must be among ".implode(', ', self::CAPABILITIES).'.';
        }

        if ($service->volumes !== [] && ! $web) {
            $errors[] = "{$key}: only app and image services have volumes.";
        }

        foreach ($service->volumes as $index => $path) {
            if (preg_match(self::MOUNT_PATH, $path) !== 1 || strlen($path) > 255
                || array_any(explode('/', $path), static fn (string $segment): bool => $segment === '.' || $segment === '..')
                || array_any(self::FORBIDDEN_MOUNTS, static fn (string $forbidden): bool => $path === $forbidden || str_starts_with($path, $forbidden.'/'))) {
                $errors[] = "{$key}: {$path} can’t be a mount path.";
            }

            foreach ($service->volumes as $other => $otherPath) {
                if ($other !== $index && ($path === $otherPath || str_starts_with($path, rtrim($otherPath, '/').'/'))) {
                    $errors[] = "{$key}: the volume at {$path} overlaps {$otherPath}.";
                }
            }
        }

        if ($service->healthcheck instanceof HealthcheckSettings && ! $web) {
            $errors[] = "{$key}: only app and image services have a health check.";
        }

        if ($service->scaling instanceof BlueprintScaling) {
            $scaling = $service->scaling;

            if (($scaling->replicas !== null && ($scaling->replicas < 0 || $scaling->replicas > 64))
                || ($scaling->cpus !== null && ($scaling->cpus < 0.05 || $scaling->cpus > 1024))
                || ($scaling->memoryMb !== null && ($scaling->memoryMb < 16 || $scaling->memoryMb > 1048576))) {
                $errors[] = "{$key}: replicas 0–64, CPUs 0.05–1024 and memory 16 MB–1 TB.";
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

        $targets = [];

        foreach ($service->connections as $connection) {
            $target = $byKey[$connection->to] ?? null;

            if ($target === null || $target === $service) {
                $errors[] = "{$key}: it connects to {$connection->to}, which isn’t another service of the blueprint.";

                continue;
            }

            if ($target->kind !== ServiceKind::Database && ! in_array($target->kind, [ServiceKind::App, ServiceKind::Image], true)) {
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

        if (preg_match(self::KEY, $variable->key) !== 1) {
            $errors[] = "{$owner}: “{$variable->key}” isn’t a valid variable name.";
        }

        if (strlen($variable->value) > 131072 || str_contains($variable->value, "\0")) {
            $errors[] = "{$owner}: {$variable->key} is too long.";
        }

        if (VariableReferences::invalid($variable->value) !== []) {
            $errors[] = "{$owner}: {$variable->key} has an invalid \${{ }} expression.";
        }

        return $errors;
    }
}

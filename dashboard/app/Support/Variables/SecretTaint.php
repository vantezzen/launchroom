<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\VariableExposure;
use App\Enums\VariableType;

/**
 * Which variables carry a secret (docs/06 §3.3, docs/09 R12/R15): a secret
 * itself, or a config value that references one, directly or through other
 * references (`NEXT_PUBLIC_DSN=${{DATABASE_URL}}` where `DATABASE_URL` embeds
 * `${{postgres.PASSWORD}}`). Resolution embeds the secret's value, so a
 * tainted value must be treated like a secret: never inlined into a browser
 * bundle, never a Docker build arg, always masked in logs.
 *
 * Works on metadata only (types and the stored `references` of each
 * VariableSpec, same precedence and scoping as SpecVariableResolver), so no
 * value is decrypted to decide.
 */
final class SecretTaint
{
    private const int MAX_DEPTH = 32;

    /** @var array<string, ?string> "scope\0key" => the secret it reaches ("scope.KEY"), null when clean */
    private array $memo = [];

    /**
     * @param  array<string, array<string, array{secret: bool, references: list<string>}>>  $scopes  scope => key => metadata
     */
    public function __construct(
        private readonly array $scopes,
    ) {}

    public static function fromSpec(EnvironmentSpec $spec): self
    {
        $meta = static fn (VariableSpec $variable): array => ['secret' => $variable->type === VariableType::Secret, 'references' => $variable->references];

        $shared = [];

        foreach ($spec->sharedVariables as $variable) {
            $shared[$variable->key] = $meta($variable);
        }

        $scopes = [VariableResolver::SHARED => $shared];

        foreach ($spec->services as $service) {
            $values = [];

            foreach ($service->sharedVariableIds as $variableId) {
                $variable = $spec->sharedVariables[$variableId] ?? null;

                if ($variable !== null) {
                    $values[$variable->key] = $meta($variable);
                }
            }

            foreach ($service->connectionVariables as $variable) {
                $values[$variable->key] = $meta($variable);
            }

            foreach ([VariableSpec::LAYER_ENVIRONMENT, VariableSpec::LAYER_BRANCH] as $layer) {
                foreach ($service->variables as $variable) {
                    if ($variable->layer === $layer) {
                        $values[$variable->key] = $meta($variable);
                    }
                }
            }

            $scopes[$service->slug] = $values;
        }

        return new self($scopes);
    }

    public function isTainted(string $scope, string $key): bool
    {
        return $this->source($scope, $key) !== null;
    }

    /**
     * The secret $scope.$key is or reaches ("scope.KEY"), or null.
     */
    public function source(string $scope, string $key): ?string
    {
        return $this->visit($scope, $key, [], 0);
    }

    /**
     * Keys of $scope whose value is or embeds a secret.
     *
     * @return list<string>
     */
    public function taintedKeys(string $scope): array
    {
        $keys = [];

        foreach (array_keys($this->scopes[$scope] ?? []) as $key) {
            if ($this->isTainted($scope, (string) $key)) {
                $keys[] = (string) $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * Every "scope.KEY" $scope.$key depends on, itself included.
     *
     * @return list<string>
     */
    public function closure(string $scope, string $key): array
    {
        $seen = [];
        $stack = [[$scope, $key]];

        while ($stack !== []) {
            [$s, $k] = array_pop($stack);
            $id = "{$s}.{$k}";

            if (isset($seen[$id]) || ! isset($this->scopes[$s][$k])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($this->scopes[$s][$k]['references'] as $reference) {
                $stack[] = $this->target($s, $reference);
            }
        }

        return array_keys($seen);
    }

    /**
     * Browser-inlined variables (NEXT_PUBLIC_*, VITE_*, …) that carry a
     * secret: one error per variable (names only).
     *
     * @param  list<string>|null  $only  scopes to check (null: every service scope)
     * @return list<ReferenceError>
     */
    public function publicExposures(?array $only = null): array
    {
        $problems = [];

        foreach ($this->scopes as $scope => $variables) {
            if ($scope === VariableResolver::SHARED || ($only !== null && ! in_array($scope, $only, true))) {
                continue;
            }

            foreach (array_keys($variables) as $key) {
                $key = (string) $key;
                $source = VariableExposure::isBrowserInlined($key) ? $this->source($scope, $key) : null;

                if ($source !== null) {
                    $problems[] = new ReferenceError($scope, $key, $source, ReferenceError::SECRET_IN_PUBLIC_VALUE);
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, true>  $visiting
     */
    private function visit(string $scope, string $key, array $visiting, int $depth): ?string
    {
        $id = $scope."\0".$key;

        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }

        $entry = $this->scopes[$scope][$key] ?? null;

        if ($entry === null || isset($visiting[$id]) || $depth > self::MAX_DEPTH) {
            // Missing references and cycles are the resolver's errors; they never resolve.
            return null;
        }

        if ($entry['secret']) {
            return $this->memo[$id] = "{$scope}.{$key}";
        }

        $visiting[$id] = true;

        foreach ($entry['references'] as $reference) {
            [$targetScope, $targetKey] = $this->target($scope, $reference);
            $source = $this->visit($targetScope, $targetKey, $visiting, $depth + 1);

            if ($source !== null) {
                return $this->memo[$id] = $source;
            }
        }

        return $this->memo[$id] = null;
    }

    /**
     * @return array{string, string} [scope, key] of a stored reference name
     */
    private function target(string $scope, string $reference): array
    {
        $dot = strpos($reference, '.');

        return $dot === false ? [$scope, $reference] : [substr($reference, 0, $dot), substr($reference, $dot + 1)];
    }
}

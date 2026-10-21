<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Exceptions\UnresolvedReferences;
use SensitiveParameter;

/**
 * Resolves `${{ … }}` references across the services of one environment
 * (docs/06 §3.1). Pure: the caller passes, per scope (service slug, plus
 * "shared" for the project's shared variables), the merged raw values after
 * precedence (system < shared opt-ins < connections < environment < branch).
 *
 * - `${{KEY}}` refers to the same scope, `${{svc.KEY}}` to another service of
 *   the same environment (references are environment-relative), and
 *   `${{shared.KEY}}` to a shared variable.
 * - Substituted values are not re-scanned: a value is verbatim, only its own
 *   expressions are resolved (recursively, in the scope it belongs to).
 * - Missing services/variables, cycles and malformed expressions are
 *   collected and thrown together as UnresolvedReferences; they block deploys.
 */
final class VariableResolver
{
    public const string SHARED = 'shared';

    /** @var array<string, string> "scope\0key" => resolved value */
    private array $resolved = [];

    /** @var array<string, true> */
    private array $visiting = [];

    /** @var array<string, ReferenceError> */
    private array $errors = [];

    /**
     * @param  array<string, array<string, string>>  $scopes  scope => key => raw value
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly array $scopes,
    ) {}

    /**
     * @throws UnresolvedReferences
     */
    public function resolve(string $scope): ResolvedEnvironment
    {
        $this->errors = [];
        $values = $this->resolveScope($scope);

        if ($this->errors !== []) {
            throw new UnresolvedReferences(array_values($this->errors));
        }

        return new ResolvedEnvironment($values);
    }

    /**
     * Every scope except "shared" (or only $only), or all errors of all of them.
     *
     * @param  list<string>|null  $only
     * @return array<string, ResolvedEnvironment>
     *
     * @throws UnresolvedReferences
     */
    public function resolveAll(?array $only = null): array
    {
        $this->errors = [];
        $all = [];

        foreach (array_keys($this->scopes) as $scope) {
            if ($scope !== self::SHARED && ($only === null || in_array($scope, $only, true))) {
                $all[$scope] = new ResolvedEnvironment($this->resolveScope($scope));
            }
        }

        if ($this->errors !== []) {
            throw new UnresolvedReferences(array_values($this->errors));
        }

        return $all;
    }

    /**
     * @phpstan-impure
     *
     * @return array<string, string>
     */
    private function resolveScope(string $scope): array
    {
        $values = [];

        foreach (array_keys($this->scopes[$scope] ?? []) as $key) {
            $value = $this->value($scope, (string) $key);

            if ($value !== null) {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }

    private function value(string $scope, string $key): ?string
    {
        $id = $scope."\0".$key;

        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        $raw = $this->scopes[$scope][$key];

        if (! VariableReferences::hasExpressions($raw)) {
            return $this->resolved[$id] = $raw;
        }

        $ok = true;

        foreach (VariableReferences::invalid($raw, allowFunctions: false) as $expression) {
            $this->error($scope, $key, $expression, ReferenceError::INVALID_EXPRESSION);
            $ok = false;
        }

        $this->visiting[$id] = true;

        $result = VariableReferences::replace($raw, function (VariableReference $reference) use ($scope, $key, &$ok): ?string {
            $target = $reference->scope ?? $scope;

            if (! isset($this->scopes[$target])) {
                $this->error($scope, $key, $reference->expression, ReferenceError::MISSING_SERVICE);
                $ok = false;

                return null;
            }

            if (! array_key_exists($reference->key, $this->scopes[$target])) {
                $this->error($scope, $key, $reference->expression, ReferenceError::MISSING_VARIABLE);
                $ok = false;

                return null;
            }

            if (isset($this->visiting[$target."\0".$reference->key])) {
                $this->error($scope, $key, $reference->expression, ReferenceError::CYCLE);
                $ok = false;

                return null;
            }

            $value = $this->value($target, $reference->key);

            if ($value === null) {
                $ok = false;
            }

            return $value;
        });

        unset($this->visiting[$id]);

        if (! $ok) {
            return null;
        }

        return $this->resolved[$id] = $result;
    }

    private function error(string $scope, string $key, string $expression, string $reason): void
    {
        $error = new ReferenceError($scope, $key, $expression, $reason);
        $this->errors[$error->message()] = $error;
    }
}

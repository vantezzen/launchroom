<?php

declare(strict_types=1);

namespace App\Support\Variables;

use App\Data\Variables\ReferenceFixData;

/**
 * "Did you mean" for broken references in the variable editor (docs/06
 * §3.4): for each reference whose service or key doesn't exist in this
 * environment, the closest valid references, by key first (a typo in the
 * key, or the same key on another service), then by scope name. When the
 * named service has a close key, only its keys are offered.
 *
 * Works on names only: the scopes and keys the editor already offers in
 * `${{` autocomplete, never values.
 */
final readonly class ReferenceSuggestions
{
    public const int LIMIT = 3;

    /**
     * @param  array<string, list<string>>  $keys  scope => its variable keys
     */
    public function __construct(
        private array $keys,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $scopes  the resolver's annotated scopes
     */
    public static function fromScopes(array $scopes): self
    {
        return new self(array_map(static fn (array $keys): array => array_map(strval(...), array_keys($keys)), $scopes));
    }

    /**
     * The broken references in `$raw`, a variable `$key` of `$scope`, each
     * with its suggestions (possibly none).
     *
     * @return list<ReferenceFixData>
     */
    public function fixes(string $scope, string $key, string $raw): array
    {
        $fixes = [];

        foreach (VariableReferences::parse($raw) as $reference) {
            $target = $reference->scope ?? $scope;

            if (isset($fixes[$reference->expression]) || in_array($reference->key, $this->keys[$target] ?? [], true)) {
                continue;
            }

            $fixes[$reference->expression] = new ReferenceFixData($reference->expression, $this->suggest($reference, $scope, $key));
        }

        return array_values($fixes);
    }

    /**
     * @return list<string>
     */
    private function suggest(VariableReference $reference, string $ownScope, string $ownKey): array
    {
        $wantedScope = $reference->scope ?? $ownScope;
        $wantedKey = strtolower($reference->key);
        $tolerance = max(2, intdiv(strlen($wantedKey), 3));
        $ranked = [];
        $inWantedScope = [];

        foreach ($this->keys as $scope => $keys) {
            foreach ($keys as $key) {
                // Pointing a variable at itself would be a cycle.
                if ($scope === $ownScope && $key === $ownKey) {
                    continue;
                }

                $keyDistance = levenshtein($wantedKey, strtolower($key));

                if ($keyDistance > $tolerance) {
                    continue;
                }

                $expression = $scope === $ownScope && $reference->scope === null ? "\${{{$key}}}" : "\${{{$scope}.{$key}}}";
                $ranked[$expression] = $keyDistance * 100 + levenshtein($wantedScope, $scope);

                if ($scope === $wantedScope) {
                    $inWantedScope[$expression] = $ranked[$expression];
                }
            }
        }

        // A typo in the key of a service that exists: its own keys are the
        // answer, the same key on other services would only be noise.
        if ($inWantedScope !== []) {
            $ranked = $inWantedScope;
        }

        uksort($ranked, static fn (string $a, string $b): int => [$ranked[$a], $a] <=> [$ranked[$b], $b]);

        return array_slice(array_keys($ranked), 0, self::LIMIT);
    }
}

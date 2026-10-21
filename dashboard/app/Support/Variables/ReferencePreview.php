<?php

declare(strict_types=1);

namespace App\Support\Variables;

use SensitiveParameter;

/**
 * The inline "resolved" preview of a variable in the editor (docs/06 §3.1,
 * §3.4): references are expanded like the resolver does (environment-
 * relative, each value's own expressions in its own scope, substituted values
 * not re-scanned), but every secret is replaced by a mask, also where a
 * config template embeds one (`postgres://app:••••••@postgres:5432/app`).
 *
 * Unlike the resolver it never throws: broken references stay visible in the
 * preview and are reported per variable, so the editor can highlight them.
 */
final readonly class ReferencePreview
{
    public const string MASK = '••••••';

    private const int MAX_DEPTH = 16;

    /**
     * @param  array<string, array<string, array{value: string, secret: bool}>>  $scopes
     */
    public function __construct(
        #[SensitiveParameter]
        private array $scopes,
    ) {}

    /**
     * @return array{preview: string, errors: list<string>}
     */
    public function preview(string $scope, string $key, #[SensitiveParameter] string $raw): array
    {
        $errors = [];
        $preview = $this->expand($scope, $raw, [$scope."\0".$key => true], 0, $errors);

        return ['preview' => $preview, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * @param  array<string, true>  $visiting
     * @param  list<string>  $errors
     */
    private function expand(string $scope, string $raw, array $visiting, int $depth, array &$errors): string
    {
        if (! VariableReferences::hasExpressions($raw)) {
            return $raw;
        }

        foreach (VariableReferences::invalid($raw, allowFunctions: false) as $expression) {
            $errors[] = "{$expression} is not a valid reference";
        }

        return VariableReferences::replace($raw, function (VariableReference $reference) use ($scope, $visiting, $depth, &$errors): ?string {
            $target = $reference->scope ?? $scope;

            if (! isset($this->scopes[$target])) {
                $errors[] = "{$reference->expression} refers to a service that doesn't exist in this environment";

                return null;
            }

            $entry = $this->scopes[$target][$reference->key] ?? null;

            if ($entry === null) {
                $errors[] = "{$reference->expression} refers to a variable that doesn't exist";

                return null;
            }

            if ($entry['secret']) {
                return self::MASK;
            }

            $id = $target."\0".$reference->key;

            if (isset($visiting[$id]) || $depth >= self::MAX_DEPTH) {
                $errors[] = "{$reference->expression} is part of a reference cycle";

                return null;
            }

            return $this->expand($target, $entry['value'], [...$visiting, $id => true], $depth + 1, $errors);
        });
    }
}

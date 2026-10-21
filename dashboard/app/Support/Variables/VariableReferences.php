<?php

declare(strict_types=1);

namespace App\Support\Variables;

/**
 * Parses `${{ … }}` expressions in variable values (docs/06 §3.1). Values are
 * otherwise verbatim: no `$VAR` interpolation, no escapes.
 */
final class VariableReferences
{
    public const string KEY_PATTERN = '[A-Za-z_][A-Za-z0-9_]{0,255}';

    public const string SCOPE_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /** Every `${{ … }}` expression, valid or not. */
    private const string EXPRESSION = '/\$\{\{(.*?)\}\}/s';

    /** The inside of a reference expression. */
    private const string REFERENCE = '/^\s*(?:('.self::SCOPE_PATTERN.')\.)?('.self::KEY_PATTERN.')\s*$/D';

    /**
     * @return list<VariableReference>
     */
    public static function parse(string $value): array
    {
        $references = [];

        foreach (self::expressions($value) as [$expression, $inner]) {
            if (preg_match(self::REFERENCE, $inner, $m) === 1) {
                $references[] = new VariableReference($expression, $m[1] === '' ? null : $m[1], $m[2]);
            }
        }

        return $references;
    }

    /**
     * Distinct stored names (`postgres.DATABASE_URL`, `shared.X`, `X`).
     *
     * @return list<string>
     */
    public static function names(string $value): array
    {
        return array_values(array_unique(array_map(static fn (VariableReference $reference): string => $reference->name(), self::parse($value))));
    }

    /**
     * Expressions that are not references (nor generator functions, when
     * $allowFunctions: they are valid on save, where they get evaluated, but
     * never at resolution). They block a deploy instead of being kept as
     * literals (docs/06 §3.1).
     *
     * @return list<string>
     */
    public static function invalid(string $value, bool $allowFunctions = true): array
    {
        $invalid = [];

        foreach (self::expressions($value) as [$expression, $inner]) {
            if (preg_match(self::REFERENCE, $inner) !== 1 && (! $allowFunctions || ! GeneratedValues::isFunction($inner))) {
                $invalid[] = $expression;
            }
        }

        return $invalid;
    }

    public static function hasExpressions(string $value): bool
    {
        return str_contains($value, '${{');
    }

    /**
     * Replace every valid reference using $resolve (null keeps the expression).
     *
     * @param  \Closure(VariableReference): ?string  $resolve
     */
    public static function replace(string $value, \Closure $resolve): string
    {
        return (string) preg_replace_callback(self::EXPRESSION, static function (array $m) use ($resolve): string {
            if (preg_match(self::REFERENCE, $m[1], $r) !== 1) {
                return $m[0];
            }

            return $resolve(new VariableReference($m[0], $r[1] === '' ? null : $r[1], $r[2])) ?? $m[0];
        }, $value);
    }

    /**
     * @return list<array{string, string}> [full expression, inner text]
     */
    private static function expressions(string $value): array
    {
        if (preg_match_all(self::EXPRESSION, $value, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(static fn (array $m): array => [$m[0], $m[1]], $matches);
    }
}

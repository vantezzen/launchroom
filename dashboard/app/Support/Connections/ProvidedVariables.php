<?php

declare(strict_types=1);

namespace App\Support\Connections;

use App\Models\Service;
use App\Support\Variables\VariableReference;
use App\Support\Variables\VariableReferences;

/**
 * Variables a service provides to whatever connects to it (D65, docs/06
 * §3.2), instead of the default `<SLUG>_URL`: Supabase's gateway gives an
 * app `NEXT_PUBLIC_SUPABASE_URL`, the anon key and the service role key.
 * Values are literal text plus references to the provider's own variables
 * (`${{PUBLIC_URL}}`, `${{ANON_KEY}}`); a connection stores them as
 * references to the provider (`${{kong.ANON_KEY}}`), so they stay
 * environment-relative and follow the provider's values. Pure.
 */
final class ProvidedVariables
{
    public const int MAX = 16;

    private const string KEY = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    /**
     * @param  array<string, string>  $variables  key => value
     * @return list<string> one sentence per problem; empty when valid
     */
    public static function errors(array $variables): array
    {
        $errors = [];

        if (count($variables) > self::MAX) {
            $errors[] = 'A service provides at most '.self::MAX.' variables to connections.';
        }

        foreach ($variables as $key => $value) {
            if (preg_match(self::KEY, (string) $key) !== 1) {
                $errors[] = "“{$key}” isn’t a valid variable name.";
            }

            if (strlen($value) > 1024 || str_contains($value, "\0")) {
                $errors[] = "{$key} is too long.";
            }

            $references = VariableReferences::parse($value);

            if (VariableReferences::invalid($value, allowFunctions: false) !== []
                || array_any($references, static fn (VariableReference $reference): bool => $reference->scope !== null)) {
                $errors[] = "{$key} may only reference the service’s own variables, like \${{PUBLIC_URL}}.";
            }
        }

        return $errors;
    }

    /**
     * What a connection to $provider injects, key => value with references
     * to the provider; null when it provides nothing of its own.
     *
     * @return array<string, string>|null
     */
    public static function of(Service $provider): ?array
    {
        $declared = $provider->connection_variables;

        if ($declared === null || $declared === []) {
            return null;
        }

        $injected = [];

        foreach ($declared as $variable) {
            $injected[$variable['key']] = VariableReferences::replace(
                $variable['value'],
                static fn (VariableReference $reference): ?string => $reference->scope === null ? '${{'.$provider->slug.'.'.$reference->key.'}}' : null,
            );
        }

        return $injected;
    }
}

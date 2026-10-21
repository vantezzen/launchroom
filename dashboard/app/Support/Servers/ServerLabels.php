<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Exceptions\DomainRuleViolation;

/**
 * Grammar of server labels and of the placement labels services require
 * (docs/02 §2, docs/09: identifiers validated by grammar). Keys like
 * Kubernetes label names without a prefix (`zone`, `provider`, `disk`,
 * `gpu`); values short and DNS-ish. `zone` and `provider` are the failure
 * domains the scheduler spreads replicas across.
 */
final class ServerLabels
{
    public const int MAX = 32;

    private const string KEY = '/^[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?$/D';

    private const string VALUE = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,61}[A-Za-z0-9])?$/D';

    /**
     * @param  array<array-key, mixed>  $labels
     * @return array<string, string> sorted by key
     *
     * @throws DomainRuleViolation
     */
    public static function validate(array $labels, string $field): array
    {
        if (count($labels) > self::MAX) {
            throw DomainRuleViolation::on($field, 'At most '.self::MAX.' labels.');
        }

        $clean = [];

        foreach ($labels as $key => $value) {
            $key = (string) $key;

            if (preg_match(self::KEY, $key) !== 1) {
                throw DomainRuleViolation::on($field, "“{$key}” is not a label name: lowercase letters, digits, “.”, “_” and “-”, up to 63 characters.");
            }

            if (! is_string($value) || preg_match(self::VALUE, $value) !== 1) {
                throw DomainRuleViolation::on($field, "The value of “{$key}” must be letters, digits, “.”, “_” or “-”, up to 63 characters.");
            }

            $clean[$key] = $value;
        }

        ksort($clean);

        return $clean;
    }
}

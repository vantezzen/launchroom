<?php

declare(strict_types=1);

namespace App\Support\Variables;

/**
 * One `${{ … }}` reference in a variable value (docs/06 §3.1):
 * `${{postgres.DATABASE_URL}}` (scope "postgres"), `${{shared.KEY}}`
 * (scope "shared") or `${{KEY}}` (scope null: the same service).
 */
final readonly class VariableReference
{
    public function __construct(
        /** The literal expression, e.g. `${{postgres.DATABASE_URL}}`. */
        public string $expression,
        public ?string $scope,
        public string $key,
    ) {}

    /**
     * Stored form (`variables.references`): `scope.KEY` or `KEY`.
     */
    public function name(): string
    {
        return $this->scope === null ? $this->key : $this->scope.'.'.$this->key;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kinds of environments (docs/06 §1): exactly one production environment per
 * project, any number of persistent ones, ephemeral previews per PR.
 */
enum EnvironmentKind: string
{
    case Production = 'production';
    case Persistent = 'persistent';
    case Preview = 'preview';

    public function sortOrder(): int
    {
        return match ($this) {
            self::Production => 0,
            self::Persistent => 1,
            self::Preview => 2,
        };
    }

    /**
     * Deploys of this kind refuse while "production secrets must differ"
     * is violated (docs/06 §3.7).
     */
    public function checksProductionSecrets(): bool
    {
        return $this === self::Production || $this === self::Preview;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

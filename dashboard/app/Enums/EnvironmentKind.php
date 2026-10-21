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

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

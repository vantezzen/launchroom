<?php

declare(strict_types=1);

namespace App\Enums;

enum ChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}

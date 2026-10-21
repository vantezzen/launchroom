<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * docs/14 §5.1: restore as a new service (default, safe) or into the
 * existing service (destructive, type-to-confirm, safety snapshot first).
 */
enum RestoreMode: string
{
    case NewService = 'new_service';
    case InPlace = 'in_place';
}

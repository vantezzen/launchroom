<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who performed an audited action.
 */
enum ActorType: string
{
    case User = 'user';
    case Server = 'server';
    case System = 'system';
}

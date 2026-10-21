<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a token was created: Settings → API tokens, or a CLI sign-in.
 */
enum ApiTokenClient: string
{
    case Dashboard = 'dashboard';
    case Cli = 'cli';
}

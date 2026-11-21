<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a token was created: Settings → API tokens, a CLI sign-in, or
 * Settings → Robot tokens (a team-owned robot, docs/16 §1.4).
 */
enum ApiTokenClient: string
{
    case Dashboard = 'dashboard';
    case Cli = 'cli';
    case Robot = 'robot';
}

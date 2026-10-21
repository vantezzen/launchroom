<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Framework-aware naming of the variables a connection injects (docs/06 §3.2).
 */
enum ConnectionStyle: string
{
    /** One URL variable: DATABASE_URL / REDIS_URL / <SLUG>_URL (Prisma, Node, Django). */
    case Url = 'url';

    /** Laravel names: DB_CONNECTION, DB_HOST, … / REDIS_HOST, … */
    case Laravel = 'laravel';

    /** Separate parts: <PREFIX>_HOST, _PORT, _USER, _PASSWORD, … */
    case Parts = 'parts';
}

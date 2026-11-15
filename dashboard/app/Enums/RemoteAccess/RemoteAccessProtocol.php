<?php

declare(strict_types=1);

namespace App\Enums\RemoteAccess;

/**
 * The wire protocol a temporary database access speaks (docs/08 §15):
 * which connection URL and client commands the panel shows.
 */
enum RemoteAccessProtocol: string
{
    case Postgres = 'postgres';
    case Redis = 'redis';
}

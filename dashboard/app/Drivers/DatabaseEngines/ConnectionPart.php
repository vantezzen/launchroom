<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

/**
 * A part of a database connection that a driver exports as a variable
 * (`${{db.PGHOST}}`). Templates name them (`${service.db.host}`), Compose
 * imports rewrite consumers to them.
 */
enum ConnectionPart: string
{
    case Url = 'url';
    case Host = 'host';
    case Port = 'port';
    case NativePort = 'native_port';
    case User = 'user';
    case Password = 'password';
    case Database = 'database';
}

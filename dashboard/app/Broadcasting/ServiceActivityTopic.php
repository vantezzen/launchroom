<?php

declare(strict_types=1);

namespace App\Broadcasting;

enum ServiceActivityTopic: string
{
    case Sessions = 'sessions';
    case RemoteAccess = 'remote_access';
    case Backups = 'backups';
    case Imports = 'imports';
    case Commands = 'commands';
}

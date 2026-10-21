<?php

declare(strict_types=1);

namespace App\Enums\Backups;

enum DestinationScope: string
{
    case Team = 'team';
    /** The control-plane destination (team_id null). */
    case Instance = 'instance';
}

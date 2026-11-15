<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pages of project settings (`/p/{project}/settings/{section}`, docs/08 §2).
 */
enum ProjectSettingsSection: string
{
    case General = 'general';
    case Environments = 'environments';
    case Previews = 'previews';
    case ConfigFile = 'config-file';
    case Logs = 'logs';
    case Danger = 'danger';
}

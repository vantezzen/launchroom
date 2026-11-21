<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The service panel's tabs in display order
 * (`/p/{project}/{environment}/s/{service}/{tab}`, docs/08 §15–16). Console
 * and Data show only with their permission, Data only for databases.
 */
enum ServicePanelTab: string
{
    case Deployments = 'deployments';
    case Logs = 'logs';
    case Commands = 'commands';
    case Console = 'console';
    case Data = 'data';
    case Settings = 'settings';
}

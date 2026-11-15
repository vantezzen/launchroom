<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pages of a service's Settings tab
 * (`/s/{service}/settings/{section}`, docs/08 §16), in display order.
 */
enum ServiceSettingsSection: string
{
    case General = 'general';
    case Source = 'source';
    case Variables = 'variables';
    case Build = 'build';
    case Deploy = 'deploy';
    case Schedule = 'schedule';
    case Domains = 'domains';
    case Networking = 'networking';
    case Firewall = 'firewall';
    case Scaling = 'scaling';
    case Volumes = 'volumes';
    /** Databases and services with volumes only. */
    case Backups = 'backups';
    case Advanced = 'advanced';
    case Danger = 'danger';

    /**
     * Former panel tabs that are settings pages now (D69): their old links
     * (`/s/{service}/variables`, `?tab=backups`) redirect here.
     *
     * @return list<self>
     */
    public static function formerTabs(): array
    {
        return [self::Variables, self::Backups];
    }
}

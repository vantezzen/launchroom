<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The tabs of a server's page (docs/08 §14): Overview lives at
 * `/servers/{server}`, the others at `/servers/{server}/{tab}`. Each tab
 * loads only the props it shows.
 */
enum ServerTab: string
{
    case Overview = 'overview';
    case Metrics = 'metrics';
    case Network = 'network';
    case Settings = 'settings';

    /**
     * Path segments of the tabs besides Overview (the route constraint).
     *
     * @return list<string>
     */
    public static function segments(): array
    {
        return [self::Metrics->value, self::Network->value, self::Settings->value];
    }
}

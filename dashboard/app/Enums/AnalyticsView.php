<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two views of a project's Analytics tab (docs/15 §2.3, §5.4, D75):
 * Traffic at `/analytics` (requests, errors, blocked requests, paths) and
 * Resources at `/analytics/resources` (CPU, memory, latency, bandwidth).
 */
enum AnalyticsView: string
{
    case Traffic = 'traffic';
    case Resources = 'resources';

    /** Where a service's Analytics opens: Traffic when it has a domain, else Resources. */
    public static function forService(bool $hasDomain): self
    {
        return $hasDomain ? self::Traffic : self::Resources;
    }
}

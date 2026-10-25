<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * Public sources of advisory data (docs/22 §4.1). Each one can be switched
 * off by the instance owner (Settings → Security).
 */
enum AdvisoryFeed: string
{
    case Osv = 'osv';
    case Kev = 'kev';
    case Eol = 'eol';

    public function label(): string
    {
        return match ($this) {
            self::Osv => 'OSV',
            self::Kev => 'CISA Known Exploited Vulnerabilities',
            self::Eol => 'endoflife.date',
        };
    }

    public function host(): string
    {
        return match ($this) {
            self::Osv => 'osv.dev',
            self::Kev => 'cisa.gov',
            self::Eol => 'endoflife.date',
        };
    }
}

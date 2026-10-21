<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of the daemon's external probe (report.json ProbeStatus).
 */
enum ProbeState: string
{
    case Starting = 'starting';
    case Passing = 'passing';
    case Failing = 'failing';
}

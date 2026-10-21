<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Result of the last "test destination" (or of the last backup that used it).
 */
enum DestinationStatus: string
{
    case Unverified = 'unverified';
    case Checking = 'checking';
    case Ok = 'ok';
    case Failing = 'failing';
}

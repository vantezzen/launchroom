<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Why a verification ran (docs/14 §10.4).
 */
enum VerificationReason: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}

<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Where the instance owner is in the recovery kit flow (docs/14 §4).
 */
enum RecoveryKitStep: string
{
    case Passphrase = 'passphrase';
    case Download = 'download';
    case KeepSafe = 'keep_safe';
    case Done = 'done';
}

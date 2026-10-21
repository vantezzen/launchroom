<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * Which way a transfer's file travels (docs/14 §12.1): an upload (browser or
 * CLI → dashboard → launchroomd) or a download (launchroomd → dashboard → browser or CLI).
 */
enum TransferDirection: string
{
    case Upload = 'upload';
    case Download = 'download';
}

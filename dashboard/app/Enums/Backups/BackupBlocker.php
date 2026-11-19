<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Why a backup action can't run now. Always shown next to the control
 * with its fix, never only as a tooltip.
 */
enum BackupBlocker: string
{
    case NoPermission = 'no_permission';
    case OwnerOnly = 'owner_only';
    case NoStorage = 'no_storage';
    case NoLaunchroomStorage = 'no_launchroom_storage';
    case StorageRemoved = 'storage_removed';
    case NoPassphrase = 'no_passphrase';
    case KitNotDownloaded = 'kit_not_downloaded';
    case NotDeployed = 'not_deployed';
    case Stopped = 'stopped';
    case ServerOffline = 'server_offline';
    case Running = 'running';
    case NothingToBackUp = 'nothing_to_back_up';
}

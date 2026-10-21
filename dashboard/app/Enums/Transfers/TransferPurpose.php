<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * What a transfer is for (docs/14 §12).
 */
enum TransferPurpose: string
{
    case DatabaseImport = 'database_import';
    case VolumeImport = 'volume_import';
    case DatabaseExport = 'database_export';
    case VolumeExport = 'volume_export';

    public function direction(): TransferDirection
    {
        return match ($this) {
            self::DatabaseImport, self::VolumeImport => TransferDirection::Upload,
            self::DatabaseExport, self::VolumeExport => TransferDirection::Download,
        };
    }

    public function subject(): DataSubject
    {
        return match ($this) {
            self::DatabaseImport, self::DatabaseExport => DataSubject::Database,
            self::VolumeImport, self::VolumeExport => DataSubject::Volume,
        };
    }
}

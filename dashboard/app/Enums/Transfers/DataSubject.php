<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * What an import or export reads or writes: a managed database or a service volume.
 */
enum DataSubject: string
{
    case Database = 'database';
    case Volume = 'volume';
}

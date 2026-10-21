<?php

declare(strict_types=1);

namespace App\Enums\Transfers;

/**
 * Where an import reads from (protocol ImportSourceKind).
 */
enum ImportSourceKind: string
{
    /** An uploaded file (a transfer). */
    case File = 'file';
    /** A database connection URL launchroomd dumps from directly. */
    case Url = 'url';
}

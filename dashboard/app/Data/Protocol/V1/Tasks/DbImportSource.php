<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use Spatie\LaravelData\Data;

/**
 * Where a database import reads from (protocol DbImportSource): an uploaded
 * file, or a connection URL sealed to the server (a TaskEnv holding exactly
 * SOURCE_URL).
 */
final class DbImportSource extends Data
{
    /**
     * @param  'file'|'url'  $kind
     */
    public function __construct(
        public string $kind,
        public ?TransferRef $file = null,
        public ?Sealed $url = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/** `logs.appended`: log text never travels over the socket. */
final class LogsAppendedData extends Data
{
    public function __construct(
        public string $deploymentId,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Logs;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * The log explorer's "Download" (docs/08 §12): the file format of every
 * matching line. The search itself is a LogQueryData from the same query.
 */
final class LogDownloadData extends Data
{
    public function __construct(
        #[In(['text', 'ndjson'])]
        public string $format = 'text',
    ) {}
}

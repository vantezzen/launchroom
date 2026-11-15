<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class LogChunk extends Data
{
    /**
     * @param  list<LogLine>  $lines
     */
    public function __construct(
        public LogSource $source,
        #[Min(1)]
        public int $seq,
        #[Min(1), Max(5000)]
        public array $lines,
    ) {}
}

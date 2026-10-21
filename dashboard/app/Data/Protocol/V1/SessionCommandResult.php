<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * SessionResult.commands[]: the outcome of one data command (docs/03 §11.1).
 */
final class SessionCommandResult extends Data
{
    /**
     * @param  'ok'|'error'|'refused'  $status
     */
    public function __construct(
        #[Regex('/^op_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public string $op,
        #[In(['ok', 'error', 'refused'])]
        public string $status,
        #[Min(0)]
        public int $rows,
        #[Min(0)]
        public int $durationMs,
        public bool|Optional $truncated = new Optional,
    ) {}
}

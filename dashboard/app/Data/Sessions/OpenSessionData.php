<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * Opening a Console or Data session. The password is only read by the
 * controller (inline confirmation) and never stored.
 */
final class OpenSessionData extends Data
{
    public const array SHELLS = ['/bin/sh', '/bin/bash', '/bin/ash', '/bin/zsh'];

    public function __construct(
        #[Regex('/^wl_[0-9A-HJKMNP-TV-Z]{26}$/')]
        public ?string $workload = null,
        #[In(self::SHELLS)]
        public ?string $shell = null,
        #[Min(2), Max(500)]
        public int $cols = 80,
        #[Min(2), Max(200)]
        public int $rows = 24,
    ) {}
}

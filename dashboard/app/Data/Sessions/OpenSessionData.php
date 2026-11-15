<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Data\Attributes\PublicIdOf;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * Opening an authorized Console or Data session.
 */
final class OpenSessionData extends Data
{
    public const array SHELLS = ['/bin/sh', '/bin/bash', '/bin/ash', '/bin/zsh'];

    public function __construct(
        #[PublicIdOf('wl')]
        public ?string $workload = null,
        #[In(self::SHELLS)]
        public ?string $shell = null,
        #[Min(2), Max(500)]
        public int $cols = 80,
        #[Min(2), Max(200)]
        public int $rows = 24,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * `POST /traces/decode`: the `X-Launchroom-Ray` value or its header line
 * (docs/23 §6). Its shape is TraceCodec's to judge, so every unreadable
 * value gets the same answer.
 */
final class DecodeTraceInput extends Data
{
    public function __construct(
        #[Max(4096)]
        public string $value,
    ) {}
}

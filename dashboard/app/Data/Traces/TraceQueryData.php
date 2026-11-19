<?php

declare(strict_types=1);

namespace App\Data\Traces;

use Spatie\LaravelData\Data;

/**
 * `GET /trace?t=`: the pasted `Launchroom-Trace` value or header line
 * (docs/23 §6). Length and shape are App\Support\Traces\TraceCodec's to
 * judge, so every unreadable value gets the same answer.
 */
final class TraceQueryData extends Data
{
    public function __construct(
        public ?string $t = null,
    ) {}
}

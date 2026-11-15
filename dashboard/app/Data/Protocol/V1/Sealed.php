<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * A value sealed to one server (protocol Sealed, `{"sealed": "<base64>"}`).
 */
final class Sealed extends Data
{
    public function __construct(
        public string $sealed,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * One line of the control-plane move's pre-flight (docs/14 §13.4).
 */
final class MovePreflightCheckData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        /** ok | info | warning | blocker */
        public string $status,
        public string $message,
    ) {}
}

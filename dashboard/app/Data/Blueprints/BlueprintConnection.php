<?php

declare(strict_types=1);

namespace App\Data\Blueprints;

use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

/**
 * A canvas edge from the service that owns it to `to` (a blueprint key),
 * injecting variables in `style` (docs/06 §3.2).
 */
final class BlueprintConnection extends Data
{
    public function __construct(
        public string $to,
        public ConnectionStyle $style = ConnectionStyle::Url,
    ) {}
}

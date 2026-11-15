<?php

declare(strict_types=1);

namespace App\Data\Broadcasts;

use Spatie\LaravelData\Data;

/** `canvas.changed`: viewers reload the canvas props. `serviceId` is set when one service changed. */
final class CanvasChangedData extends Data
{
    public function __construct(
        public ?string $serviceId,
    ) {}
}

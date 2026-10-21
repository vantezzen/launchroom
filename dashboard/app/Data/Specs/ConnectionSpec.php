<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

final class ConnectionSpec extends Data
{
    public function __construct(
        public string $id,
        public string $fromServiceId,
        public string $toServiceId,
        public ConnectionMode $mode,
        public ConnectionStyle $style,
    ) {}
}

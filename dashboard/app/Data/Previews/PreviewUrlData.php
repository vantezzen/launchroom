<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

final class PreviewUrlData extends Data
{
    public function __construct(
        public string $service,
        public string $url,
    ) {}
}

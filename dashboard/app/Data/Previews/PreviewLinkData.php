<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\PreviewStatus;
use Spatie\LaravelData\Data;

/**
 * A preview environment in the environment switcher (grouped under "Previews").
 */
final class PreviewLinkData extends Data
{
    public function __construct(
        public string $environmentId,
        public int $number,
        public string $title,
        public PreviewStatus $status,
    ) {}
}

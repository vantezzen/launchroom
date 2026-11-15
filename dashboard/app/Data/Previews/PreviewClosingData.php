<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\Previews\PreviewStatus;
use Spatie\LaravelData\Data;

/**
 * `previews.data.pendingClose`: a close that waits until the preview's
 * environment can be torn down.
 */
final class PreviewClosingData extends Data
{
    public function __construct(
        public PreviewStatus $to,
        public string $reason,
    ) {}
}

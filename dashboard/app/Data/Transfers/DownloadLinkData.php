<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use Spatie\LaravelData\Data;

/**
 * A one-use download link (docs/14 §12.5): valid for a few minutes, for the
 * user who made it.
 */
final class DownloadLinkData extends Data
{
    public function __construct(
        public string $url,
        public string $expiresAt,
    ) {}
}

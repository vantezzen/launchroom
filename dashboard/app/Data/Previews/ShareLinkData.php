<?php

declare(strict_types=1);

namespace App\Data\Previews;

use Spatie\LaravelData\Data;

/**
 * A share link of a protected preview. `url` carries the signed token and is
 * only included for users who may share the preview.
 */
final class ShareLinkData extends Data
{
    public function __construct(
        public string $id,
        public ?string $label,
        public string $expiresAt,
        public bool $usable,
        public ?string $lastUsedAt,
        public ?string $createdBy,
        public ?string $url,
    ) {}
}

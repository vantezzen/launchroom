<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * One file of a RepoInspection: path relative to the app directory, its
 * text (cut at 32 KiB).
 */
final class InspectedFile extends Data
{
    public function __construct(
        #[Max(1024)]
        public string $path,
        #[Max(32768)]
        public string $content,
        public bool $truncated,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Removing a service volume (staged, docs/14 §9.12). A volume that holds
 * files (it was created on a server) needs its mount path typed as the
 * confirmation; one that was never deployed doesn't.
 */
final class RemoveVolumeData extends Data
{
    public function __construct(
        #[Max(255)]
        public ?string $confirm = null,
    ) {}
}
